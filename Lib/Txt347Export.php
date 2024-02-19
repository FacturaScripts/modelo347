<?php
/**
 * This file is part of Modelo347 plugin for FacturaScripts
 * Copyright (C) 2020-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\Modelo347\Lib;

use FacturaScripts\Core\Base\ToolBox;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\Pais;

class Txt347Export
{
    /** @var Empresa */
    protected static $company;

    /** @var array */
    protected static $customersData;

    /** @var Ejercicio */
    protected static $exercise;

    /** @var array */
    protected static $suppliersData;

    /** @var float */
    protected static $total = 0.0;

    public static function export(string $codejercicio, array $customersData, array $suppliersData): string
    {
        self::$customersData = $customersData;
        self::$suppliersData = $suppliersData;
        self::loadExercise($codejercicio);
        self::loadCompany();

        $customerData = self::getCustomerData();
        $supplierData = self::getSupplierData();
        $companyData = self::getCompanyData();

        return $companyData . $customerData . $supplierData;
    }

    protected static function checkCifNif(array $item): string
    {
        if (strtoupper($item['codpais']) !== 'ES'
            && false === in_array(strtoupper($item['tipoidfiscal']), ['DNI', 'CIF', 'NIF'])) {
            return self::formatString('', 9, ' ', STR_PAD_RIGHT);
        }

        return self::formatString($item['cifnif'], 9, '0', STR_PAD_RIGHT);
    }

    protected static function formatOnlyNumber(string $number): string
    {
        // eliminamos cualquier carácter que no sea un número
        return preg_replace('/[^0-9]/', '', $number);
    }

    protected static function formatString(string $string, int $length, string $charter, int $align): string
    {
        // eliminamos los acentos y caracteres especiales
        $string = self::sanitize($string);

        // pasamos el string a mayúsculas
        $string = strtoupper($string);

        // limitamos el tamaño del string
        $string = self::limitString($string, $length);

        // rellenamos con el carácter indicado hasta el tamaño indicado, según la alineación indicada
        return str_pad($string, $length, $charter, $align);
    }

    protected static function loadCompany(): void
    {
        self::$company = new Empresa();
        self::$company->loadFromCode(self::$exercise->idempresa);
    }

    protected static function getCompanyData(): string
    {
        return '1' // TIPO DE REGISTRO
            . '347' // MODELO DECLARACIÓN
            . date('Y', strtotime(self::$exercise->fechainicio)) // EJERCICIO
            . self::formatString(self::$company->cifnif, 9, '0', STR_PAD_RIGHT) // NIF DEL DECLARANTE
            . self::formatString(self::$company->nombre, 40, ' ', STR_PAD_LEFT) // APELLIDOS Y NOMBRE, RAZÓN SOCIAL O DENOMINACIÓN DEL DECLARANTE
            . 'T' // TIPO DE SOPORTE
            . self::formatString(self::formatOnlyNumber(self::$company->telefono1), 9, '0', STR_PAD_RIGHT)
            . self::formatString(self::$company->administrador, 40, ' ', STR_PAD_LEFT) // PERSONA CON QUIÉN RELACIONARSE
            . self::formatString('', 13, '0', STR_PAD_RIGHT) // NÚMERO IDENTIFICATIVO DE LA DECLARACIÓN
            . self::formatString('', 1, ' ', STR_PAD_LEFT)
            . self::formatString('', 1, ' ', STR_PAD_LEFT) // DECLARACIÓN COMPLEMENTARIA O SUSTITUTIVA
            . self::formatString('', 13, '0', STR_PAD_RIGHT) // NÚMERO IDENTIFICATIVO DE LA DECLARACIÓN ANTERIOR
            . self::formatString(count(self::$customersData) + count(self::$suppliersData), 9, '0', STR_PAD_RIGHT) // NÚMERO TOTAL DE PERSONAS Y ENTIDADES
            . (self::$total < 0 ? 'N' : ' ')
            . self::formatString((int)self::$total, 13, '0', STR_PAD_LEFT)
            . self::formatString(self::getDecimal(self::$total), 2, '0', STR_PAD_LEFT) // IMPORTE TOTAL ANUAL DE LAS OPERACIONES
            . self::formatString('', 9, '0', STR_PAD_RIGHT) // NÚMERO TOTAL DE INMUEBLES
            . (self::$total < 0 ? 'N' : ' ')
            . self::formatString('', 15, '0', STR_PAD_LEFT) // IMPORTE TOTAL DE LAS OPERACIONES DE ARRENDAMIENTO DE LOCALES DE NEGOCIO
            . self::formatString('', 205, ' ', STR_PAD_LEFT) // BLANCOS
            . self::formatString('', 9, ' ', STR_PAD_RIGHT) // NIF DEL REPRESENTANTE LEGAL
            . self::formatString('', 88, ' ', STR_PAD_LEFT) // BLANCOS
            . self::formatString('', 13, ' ', STR_PAD_LEFT); // SELLO ELECTRONICO
    }

    protected static function getCustomerData(): string
    {
        $txt = '';
        foreach (self::$customersData as $item) {
            self::$total += $item['total'];

            $txt .= "\n"
                . '2' // TIPO DE REGISTRO
                . '347' // MODELO DECLARACIÓN
                . date('Y', strtotime(self::$exercise->fechainicio)) // EJERCICIO
                . self::formatString(self::$company->cifnif, 9, '0', STR_PAD_RIGHT) // NIF DEL DECLARANTE
                . self::checkCifNif($item) // NIF DEL DECLARADO
                . self::formatString('', 9, ' ', STR_PAD_RIGHT) // NIF DEL REPRESENTANTE LEGAL
                . self::formatString($item['cliente'], 40, ' ', STR_PAD_LEFT) // APELLIDOS Y NOMBRE, RAZÓN SOCIAL O DENOMINACIÓN DEL DECLARADO
                . 'D' // TIPO DE HOJA
                . self::getProvincia($item['provincia']) . self::getPais($item['codpais']) // CÓDIGO PROVINCIA/PAIS
                . ' ' // BLANCOS
                . 'B' // CLAVE OPERACIÓN
                . (self::$total < 0 ? 'N' : ' ')
                . self::formatString((int)self::$total, 13, '0', STR_PAD_LEFT)
                . self::formatString(self::getDecimal(self::$total), 2, '0', STR_PAD_LEFT) // IMPORTE ANUAL DE LAS OPERACIONES
                . ' ' // OPERACIÓN SEGURO
                . ' ' // ARRENDAMIENTO LOCAL NEGOCIO
                . self::formatString('', 15, '0', STR_PAD_RIGHT) // IMPORTE PERCIBIDO EN METÁLICO
                . (self::$total < 0 ? 'N' : ' ')
                . self::formatString('', 15, '0', STR_PAD_LEFT) // IMPORTE ANUAL PERCIBIDO POR TRANSMISIONES DE INMUEBLES SUJETAS A IVA
                . self::formatString('', 4, '0', STR_PAD_RIGHT) // EJERCICIO
                . ($item['t1'] < 0 ? 'N' : ' ')
                . self::formatString((int)$item['t1'], 13, '0', STR_PAD_LEFT)
                . self::formatString(self::getDecimal($item['t1']), 2, '0', STR_PAD_LEFT) // IMPORTE DE LAS OPERACIONES PRIMER TRIMESTRE
                . ' ' . self::formatString('', 15, '0', STR_PAD_LEFT) // IMPORTE PERCIBIDO POR TRANSMISIONES DE INMUEBLES SUJETAS A IVA PRIMER TRIMESTRE
                . ($item['t2'] < 0 ? 'N' : ' ')
                . self::formatString((int)$item['t2'], 13, '0', STR_PAD_LEFT)
                . self::formatString(self::getDecimal($item['t2']), 2, '0', STR_PAD_LEFT) // IMPORTE DE LAS OPERACIONES SEGUNDO TRIMESTRE
                . ' ' . self::formatString('', 15, '0', STR_PAD_LEFT) // IMPORTE PERCIBIDO POR TRANSMISIONES DE INMUEBLES SUJETAS A IVA SEGUNDO TRIMESTRE
                . ($item['t3'] < 0 ? 'N' : ' ')
                . self::formatString((int)$item['t3'], 13, '0', STR_PAD_LEFT)
                . self::formatString(self::getDecimal($item['t3']), 2, '0', STR_PAD_LEFT) // IMPORTE DE LAS OPERACIONES TERCER TRIMESTRE
                . ' ' . self::formatString('', 15, '0', STR_PAD_LEFT) // IMPORTE PERCIBIDO POR TRANSMISIONES DE INMUEBLES SUJETAS A IVA TERCER TRIMESTRE
                . ($item['t4'] < 0 ? 'N' : ' ')
                . self::formatString((int)$item['t4'], 13, '0', STR_PAD_LEFT)
                . self::formatString(self::getDecimal($item['t4']), 2, '0', STR_PAD_LEFT) // IMPORTE DE LAS OPERACIONES CUARTO TRIMESTRE
                . ' ' . self::formatString('', 15, '0', STR_PAD_LEFT) // IMPORTE PERCIBIDO POR TRANSMISIONES DE INMUEBLES SUJETAS A IVA CUARTO TRIMESTRE
                . self::formatString('', 17, ' ', STR_PAD_LEFT) // NIF OPERADOR COMUNITARIO
                . ' ' // OPERACIONES RÉGIMEN ESPECIAL CRITERIO DE CAJA IVA
                . ' ' // OPERACIÓN CON INVERSIÓN DEL SUJETO PASIVO
                . ' ' // OPERACIÓN CON BIENES VINCULADOS O DESTINADOS A VINCULARSE AL RÉGIMEN DE DEPÓSITO DISTINTO DEL ADUANERO
                . self::formatString('', 16, ' ', STR_PAD_LEFT) // IMPORTE ANUAL DE LAS OPERACIONES DEVENGADAS CONFORME AL CRITERIO DE CAJA DEL IVA
                . self::formatString('', 201, ' ', STR_PAD_LEFT); // BLANCOS
        }
        return $txt;
    }

    protected static function getDecimal($number): int
    {
        return ((float)$number - (int)$number) * 100;
    }

    protected static function loadExercise(string $codejercicio): void
    {
        self::$exercise = new Ejercicio();
        self::$exercise->loadFromCode($codejercicio);
    }

    protected static function getPais(string $codpais): string
    {
        $paisModel = new Pais();
        if ($paisModel->loadFromCode($codpais) && $paisModel->codiso !== 'ES') {
            return self::formatString($paisModel->codiso, 2, '', STR_PAD_LEFT);
        }

        return self::formatString('', 2, ' ', STR_PAD_LEFT);
    }

    protected static function getProvincia(?string $provincia): string
    {
        switch (strtolower($provincia)) {
            case 'araba':
            case 'alava':
            case 'álava':
                return '01';

            case 'albacete':
                return '02';

            case 'alicante':
            case 'alacant':
                return '03';

            case 'almeria':
            case 'almería':
                return '04';

            case 'asturias':
                return '33';

            case 'avila':
            case 'ávila':
                return '05';

            case 'badajoz':
                return '06';

            case 'barcelona':
                return '08';

            case 'burgos':
                return '09';

            case 'caceres':
            case 'cáceres':
                return '10';

            case 'cadiz':
            case 'cádiz':
                return '11';

            case 'cantabria':
                return '39';

            case 'castellon':
            case 'castellón':
            case 'castello':
                return '12';

            case 'ceuta':
                return '51';

            case 'ciudad real':
                return '13';

            case 'cordoba':
            case 'córdoba':
                return '14';

            case 'coruña':
            case 'a coruña':
                return '15';

            case 'cuenca':
                return '16';

            case 'girona':
                return '17';

            case 'granada':
                return '18';

            case 'guadalajara':
                return '19';

            case 'guipuzcoa':
            case 'gipúzkoa':
            case 'gipuzkoa':
                return '20';

            case 'huelva':
                return '21';

            case 'huesca':
                return '22';

            case 'illes balears':
            case 'islas baleares':
                return '07';

            case 'jaen':
            case 'jaén':
                return '23';

            case 'las palmas':
                return '35';

            case 'la rioja':
                return '26';

            case 'leon':
            case 'león':
                return '24';

            case 'lleida':
                return '25';

            case 'lugo':
                return '27';

            case 'madrid':
                return '28';

            case 'malaga':
            case 'málaga':
                return '29';

            case 'melilla':
                return '52';

            case 'murcia':
                return '30';

            case 'navarra':
                return '31';

            case 'ourense':
                return '32';

            case 'palencia':
                return '34';

            case 'pontevedra':
                return '36';

            case 'santa cruz de tenerife':
            case 'tenerife':
            case 's.c.tenerife':
                return '38';

            case 'salamanca':
                return '37';

            case 'segovia':
                return '40';

            case 'sevilla':
                return '41';

            case 'soria':
                return '42';

            case 'tarragona':
                return '43';

            case 'teruel':
                return '44';

            case 'toledo':
                return '45';

            case 'valencia':
            case 'valència':
                return '46';

            case 'valladolid':
                return '47';

            case 'vizcaya':
            case 'bizkaia':
                return '48';

            case 'zamora':
                return '49';

            case 'zaragoza':
                return '50';

            default:
                return '99';
        }
    }

    protected static function getSupplierData(): string
    {
        $txt = '';
        foreach (self::$suppliersData as $item) {
            self::$total += $item['total'];

            $txt .= "\n"
                . '2' // TIPO DE REGISTRO
                . '347' // MODELO DECLARACIÓN
                . date('Y', strtotime(self::$exercise->fechainicio)) // EJERCICIO
                . self::formatString(self::$company->cifnif, 9, '0', STR_PAD_RIGHT) // NIF DEL DECLARANTE
                . self::checkCifNif($item) // NIF DEL DECLARADO
                . self::formatString('', 9, ' ', STR_PAD_RIGHT) // NIF DEL REPRESENTANTE LEGAL
                . self::formatString($item['proveedor'], 40, ' ', STR_PAD_LEFT) // APELLIDOS Y NOMBRE, RAZÓN SOCIAL O DENOMINACIÓN DEL DECLARADO
                . 'D' // TIPO DE HOJA
                . self::getProvincia($item['provincia']) . self::getPais($item['codpais']) // CÓDIGO PROVINCIA/PAIS
                . ' ' // BLANCOS
                . 'A' // CLAVE OPERACIÓN
                . (self::$total < 0 ? 'N' : ' ')
                . self::formatString((int)self::$total, 13, '0', STR_PAD_LEFT)
                . self::formatString(self::getDecimal(self::$total), 2, '0', STR_PAD_LEFT) // IMPORTE ANUAL DE LAS OPERACIONES
                . ' ' // OPERACIÓN SEGURO
                . ' ' // ARRENDAMIENTO LOCAL NEGOCIO
                . self::formatString('', 15, '0', STR_PAD_RIGHT) // IMPORTE PERCIBIDO EN METÁLICO
                . (self::$total < 0 ? 'N' : ' ')
                . self::formatString('', 15, '0', STR_PAD_LEFT) // IMPORTE ANUAL PERCIBIDO POR TRANSMISIONES DE INMUEBLES SUJETAS A IVA
                . self::formatString('', 4, '0', STR_PAD_RIGHT) // EJERCICIO
                . ($item['t1'] < 0 ? 'N' : ' ')
                . self::formatString((int)$item['t1'], 13, '0', STR_PAD_LEFT)
                . self::formatString(self::getDecimal($item['t1']), 2, '0', STR_PAD_LEFT) // IMPORTE DE LAS OPERACIONES PRIMER TRIMESTRE
                . ' ' . self::formatString('', 15, '0', STR_PAD_LEFT) // IMPORTE PERCIBIDO POR TRANSMISIONES DE INMUEBLES SUJETAS A IVA PRIMER TRIMESTRE
                . ($item['t2'] < 0 ? 'N' : ' ')
                . self::formatString((int)$item['t2'], 13, '0', STR_PAD_LEFT)
                . self::formatString(self::getDecimal($item['t2']), 2, '0', STR_PAD_LEFT) // IMPORTE DE LAS OPERACIONES SEGUNDO TRIMESTRE
                . ' ' . self::formatString('', 15, '0', STR_PAD_LEFT) // IMPORTE PERCIBIDO POR TRANSMISIONES DE INMUEBLES SUJETAS A IVA SEGUNDO TRIMESTRE
                . ($item['t3'] < 0 ? 'N' : ' ')
                . self::formatString((int)$item['t3'], 13, '0', STR_PAD_LEFT)
                . self::formatString(self::getDecimal($item['t3']), 2, '0', STR_PAD_LEFT) // IMPORTE DE LAS OPERACIONES TERCER TRIMESTRE
                . ' ' . self::formatString('', 15, '0', STR_PAD_LEFT) // IMPORTE PERCIBIDO POR TRANSMISIONES DE INMUEBLES SUJETAS A IVA TERCER TRIMESTRE
                . ($item['t4'] < 0 ? 'N' : ' ')
                . self::formatString((int)$item['t4'], 13, '0', STR_PAD_LEFT)
                . self::formatString(self::getDecimal($item['t4']), 2, '0', STR_PAD_LEFT) // IMPORTE DE LAS OPERACIONES CUARTO TRIMESTRE
                . ' ' . self::formatString('', 15, '0', STR_PAD_LEFT) // IMPORTE PERCIBIDO POR TRANSMISIONES DE INMUEBLES SUJETAS A IVA CUARTO TRIMESTRE
                . self::formatString('', 17, ' ', STR_PAD_LEFT) // NIF OPERADOR COMUNITARIO
                . ' ' // OPERACIONES RÉGIMEN ESPECIAL CRITERIO DE CAJA IVA
                . ' ' // OPERACIÓN CON INVERSIÓN DEL SUJETO PASIVO
                . ' ' // OPERACIÓN CON BIENES VINCULADOS O DESTINADOS A VINCULARSE AL RÉGIMEN DE DEPÓSITO DISTINTO DEL ADUANERO
                . self::formatString('', 16, ' ', STR_PAD_LEFT) // IMPORTE ANUAL DE LAS OPERACIONES DEVENGADAS CONFORME AL CRITERIO DE CAJA DEL IVA
                . self::formatString('', 201, ' ', STR_PAD_LEFT); // BLANCOS
        }
        return $txt;
    }

    protected static function limitString(string $string, int $length): string
    {
        return substr($string, 0, $length);
    }

    protected static function sanitize(?string $txt): string
    {
        $changes = ['/à/' => 'a', '/á/' => 'a', '/â/' => 'a', '/ã/' => 'a', '/ä/' => 'a',
            '/å/' => 'a', '/æ/' => 'ae', '/ç/' => 'c', '/è/' => 'e', '/é/' => 'e', '/ê/' => 'e',
            '/ë/' => 'e', '/ì/' => 'i', '/í/' => 'i', '/î/' => 'i', '/ï/' => 'i', '/ð/' => 'd',
            '/ñ/' => 'n', '/ò/' => 'o', '/ó/' => 'o', '/ô/' => 'o', '/õ/' => 'o', '/ö/' => 'o',
            '/ő/' => 'o', '/ø/' => 'o', '/ù/' => 'u', '/ú/' => 'u', '/û/' => 'u', '/ü/' => 'u',
            '/ű/' => 'u', '/ý/' => 'y', '/þ/' => 'th', '/ÿ/' => 'y',
            '/&quot;/' => '-', '/´/' => '/\'/', '/€/' => 'EUR', '/º/' => '.',
            '/À/' => 'A', '/Á/' => 'A', '/Â/' => 'A', '/Ä/' => 'A',
            '/Ç/' => 'C', '/È/' => 'E', '/É/' => 'E', '/Ê/' => 'E',
            '/Ë/' => 'E', '/Ì/' => 'I', '/Í/' => 'I', '/Î/' => 'I', '/Ï/' => 'I',
            '/Ñ/' => 'N', '/Ò/' => 'O', '/Ó/' => 'O', '/Ô/' => 'O', '/Ö/' => 'O',
            '/Ù/' => 'U', '/Ú/' => 'U', '/Û/' => 'U', '/Ü/' => 'U',
            '/Ý/' => 'Y', '/Ÿ/' => 'Y'
        ];

        $txtNoHtml = ToolBox::utils()->noHtml($txt) ?? '';
        return preg_replace(array_keys($changes), $changes, $txtNoHtml);
    }
}
