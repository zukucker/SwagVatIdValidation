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

        // The bff validator api does only support 'EL' as greece iso. Therefore, we replace the original GR with the EL.
        $data['UstId_2'] = \str_replace('GR', 'EL', $data['UstId_2']);

        $headers = [
            'Content-Type' => 'application/json',
        ];

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
                        'anfragendeUstid' => $data['UstId_1'],
                        'angefragteUstid' => $data['UstId_2'],
                    ]),
                ]
            );
            $plainResponse = (string) $response->getBody();
            $jsonResponse = json_decode($plainResponse);

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
