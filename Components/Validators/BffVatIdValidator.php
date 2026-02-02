<?php
declare(strict_types=1);
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagVatIdValidation\Components\Validators;

use Shopware\Components\HttpClient\GuzzleFactory;
use Shopware\Components\HttpClient\GuzzleHttpClient;
use Shopware\Components\HttpClient\RequestException;
use SwagVatIdValidation\Components\VatIdConfigReaderInterface;
use SwagVatIdValidation\Components\VatIdCustomerInformation;
use SwagVatIdValidation\Components\VatIdInformation;
use SwagVatIdValidation\Components\VatIdValidatorResult;

abstract class BffVatIdValidator implements VatIdValidatorInterface
{
    /**
     * The Bff validator (http://evatr.bff-online.de) works only for requests from german VAT Ids for foreign VAT Ids.
     * When you request a qualified confirmation request, it returns whether an address data is correct or not.
     * Some countries (like Germany) does not return the address data, so the address data will not be checked.
     * Additionally you can order an official mail confirmation for qualified confirmation requests.
     */

    const DEBUG_STREET = 'Musterstrasse 22';
    const DEBUG_COMPANY = 'Musterhaus GmbH & Co KG';
    const DEBUG_POSTCODE = '12345';
    const DEBUG_LOCATION = 'Musterort';
    const DEBUG_A_USTID = 'DE123456789';
    const DEBUG_B_USTID = 'ATU12345678';

    /**
     * @var VatIdValidatorResult
     */
    protected $result;

    /**
     * @var bool
     */
    protected $confirmation;

    /**
     * @var \Shopware_Components_Snippet_Manager
     */
    protected $snippetManager;

    /**
     * @var \Shopware_Components_Config
     */
    private $config;

    /**
     * @var ClientInterface
     */
    private $guzzleClient;

    /**
     * Constructor sets the snippet namespace
     */
    public function __construct(
        \Shopware_Components_Snippet_Manager $snippetManager, 
        \Shopware_Components_Config $config,
        GuzzleFactory $guzzleFactory
    )
    {
        $this->snippetManager = $snippetManager;
        $this->config = $config;
        $this->confirmation = $this->config->get(VatIdConfigReaderInterface::OFFICIAL_CONFIRMATION);
        $this->guzzleClient = $guzzleFactory->createClient();
    }

    /**
     * {@inheritdoc}
     */
    public function check(VatIdCustomerInformation $customerInformation, VatIdInformation $shopInformation)
    {
        $this->result = new VatIdValidatorResult($this->snippetManager, 'bffValidator', $this->config);

        $data = $this->getData($customerInformation, $shopInformation);

        // is this still a thing?
        //
        // The bff validator api does only support 'EL' as greece iso. Therefore, we replace the original GR with the EL.
        //$data['UstId_2'] = \str_replace('GR', 'EL', $data['UstId_2']);

        $headers = [
            'Content-Type' => 'application/json',
        ];

        $debug = true;

        try{
            $response = $this->guzzleClient->request(
                'POST',
                'https://api.evatr.vies.bzst.de/app/v1/abfrage',
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept'       => 'application/json',
                    ],
                    'body' => json_encode([
                        'anfragendeUstid' => $debug ? self::DEBUG_A_USTID : $data['UstId_1'],
                        'angefragteUstid' => $debug ? self::DEBUG_B_USTID : $data['UstId_2'],
                        'firmenname' => $debug ? self::DEBUG_COMPANY : $data['Firmenname'],
                        'strasse' => $debug ? self::DEBUG_STREET : $data['Strasse'],
                        'plz' => $debug ? self::DEBUG_POSTCODE : $data['PLZ'],
                        'ort' => $debug ? self::DEBUG_LOCATION : $data['Ort'],
                    ]),
                ]
            );
            $plainResponse = (string) $response->getBody();
            $jsonResponse = json_decode($plainResponse);
            dump($jsonResponse);
            die();

            if (empty($jsonResponse)) {
                $this->result->setServiceUnavailable();

                return $this->result;
            }

            $this->createSimpleValidatorResult($jsonResponse);
            $this->addExtendedResults($jsonResponse);
        }catch(\GuzzleHttp\Exception\RequestException $exception){
            $response = $exception->getResponse();
            $plainResponse = (string) $response->getBody();
            $jsonResponse = json_decode($plainResponse);
            $this->createSimpleValidatorResult($jsonResponse);
            $this->addExtendedResults($jsonResponse);
        }

        return $this->result;
    }

    /**
     * Helper function that returns an array in the format the validator needs it
     *
     * @return array{UstId_1: string, UstId_2: string, Firmenname: string|null, Ort: string, PLZ: string, Strasse: string|null, Druck: 'ja'|'nein'|''}
     */
    abstract protected function getData(VatIdCustomerInformation $customerInformation, VatIdInformation $shopInformation);

    /**
     * Helper function to set the address data results of a qualified confirmation request
     *
     * @param array $response
     *
     * @return void
     */
    abstract protected function addExtendedResults($response);

    /**
     * Helper function to set the VAT Id result of a confirmation request
     */
    private function createSimpleValidatorResult($jsonResponse): void
    {
        if ($jsonResponse->status === 'evatr-0000') {
            return;
        }

        $this->result->setVatIdInvalid($response->status);
    }
}
