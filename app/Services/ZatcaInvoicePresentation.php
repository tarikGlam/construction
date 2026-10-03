<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/** Read immutable fiscal identity, never current warehouse/general settings. */
class ZatcaInvoicePresentation
{
    public function fromXml(string $xml, string $environment): array
    {
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false) {
            throw new RuntimeException('The stored fiscal invoice XML is unavailable or invalid.');
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $dom->loadXML($xml, LIBXML_NONET)) {
                throw new RuntimeException('The stored fiscal invoice XML cannot be read.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $party = '/*/cac:AccountingSupplierParty/cac:Party';
        $value = fn (string $path): string => trim($xpath->evaluate('string('.$party.'/'.$path.')'));
        $name = $value('cac:PartyLegalEntity/cbc:RegistrationName');
        $vat = $value('cac:PartyTaxScheme/cbc:CompanyID');
        if ($name === '' || $vat === '') {
            throw new RuntimeException('The stored fiscal invoice is missing its seller identity.');
        }
        $address = [];
        $addressFields = ['cbc:StreetName', 'cbc:BuildingNumber', 'cbc:PlotIdentification', 'cbc:CitySubdivisionName', 'cbc:CityName', 'cbc:PostalZone', 'cac:Country/cbc:IdentificationCode'];
        foreach ($addressFields as $field) {
            $address[] = $value('cac:PostalAddress/'.$field);
        }

        $buyerValue = fn (string $path): string => trim($xpath->evaluate('string(/*/cac:AccountingCustomerParty/cac:Party/'.$path.')'));
        $buyerName = $buyerValue('cac:PartyLegalEntity/cbc:RegistrationName');
        $buyerVat = $buyerValue('cac:PartyTaxScheme/cbc:CompanyID');
        $buyerAddress = array_map(fn ($field) => $buyerValue('cac:PostalAddress/'.$field), $addressFields);

        return [
            'seller_name' => $name,
            'seller_vat' => $vat,
            'seller_registration' => $value('cac:PartyIdentification/cbc:ID'),
            'seller_address' => implode(', ', array_filter($address, fn ($part) => $part !== '')),
            'environment' => $environment,
            'is_test' => $environment !== 'production',
            'buyer' => $buyerName === '' && $buyerVat === '' ? null : [
                'name' => $buyerName,
                'vat' => $buyerVat,
                'registration' => $buyerValue('cac:PartyIdentification/cbc:ID'),
                'address' => implode(', ', array_filter($buyerAddress, fn ($part) => $part !== '')),
            ],
        ];
    }
}
