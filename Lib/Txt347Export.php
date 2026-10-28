<?php
/**
 * This file is part of Modelo347 plugin for FacturaScripts
 * Copyright (C) 2020-2026 Carlos Garcia Gomez <carlos@facturascripts.com>
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

use FacturaScripts\Core\DataSrc\Paises;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\Empresa;

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

    /** @var string */
    protected static $declarationType = '';

    /** @var string */
    protected static $justificante = '';

    /** @var string */
    protected static $justificanteAnterior = '';

    /** @var float */
    protected static $total = 0.0;

    public static function export(string $codejercicio, array $customersData, array $suppliersData, string $justificante = '', string $declarationType = '', string $justificanteAnterior = ''): string
    {
        self::$total = 0.0;
        self::$customersData = $customersData;
        self::$suppliersData = $suppliersData;
        self::$justificante = $justificante;
        self::$declarationType = $declarationType;
        self::$justificanteAnterior = $justificanteAnterior;
        self::loadExercise($codejercicio);
        self::loadCompany();

        $customerData = self::getCustomerData();
        $supplierData = self::getSupplierData();
        $companyData = self::getCompanyData();

        return mb_convert_encoding($companyData . $customerData . $supplierData, 'ISO-8859-1', 'UTF-8');
    }

    protected static function checkCifNif(array $item): string
    {
        if (false === self::isSpanish($item['codpais'] ?? '')) {
            return self::formatString('', 9, ' ', STR_PAD_RIGHT);
        }

        return self::formatString($item['cifnif'], 9, '0', STR_PAD_LEFT);
    }

    protected static function isSpanish(string $codpais): bool
    {
        $pais = Paises::get($codpais);
        return strtoupper($pais->codiso ?? '') === 'ES';
    }

    /**
     * NIF del operador comunitario (17 caracteres): las dos primeras letras de su NIF-IVA y el número. Solo para
     * los de otros Estados miembros; el resto, en blanco. El prefijo coincide con el código ISO salvo en Grecia
     * (EL en lugar de GR) y del número se quitan los dos. El Reino Unido no es de la UE desde el 31/01/2020,
     * aunque el core aún lo incluya en Paises::MIEMBROS_UE (PR NeoRazorX/facturascripts#2059).
     */
    protected static function getNifOperadorComunitario(array $item): string
    {
        $codpais = $item['codpais'] ?? '';
        $pais = Paises::get($codpais);
        $iso = strtoupper($pais->codiso ?? '');
        if (false === self::isSpanish($codpais) && !empty($item['cifnif']) && Paises::miembroUE($codpais) && $iso !== 'GB') {
            $prefix = self::vatPrefix($iso);
            $countryCode = self::formatString($prefix, 2, ' ', STR_PAD_RIGHT);
            $vat = preg_replace('/[^A-Z0-9]/', '', strtoupper($item['cifnif']));
            foreach (array_unique([$prefix, $iso]) as $start) {
                if (!empty($start) && str_starts_with($vat, $start)) {
                    $vat = substr($vat, strlen($start));
                    break;
                }
            }
            return $countryCode . self::formatString($vat, 15, ' ', STR_PAD_RIGHT);
        }
        return self::formatString('', 17, ' ', STR_PAD_RIGHT);
    }

    /**
     * Prefijo del NIF-IVA a partir del código ISO del país: EL para Grecia (GR) y el propio código para el resto.
     * Cuando el core tenga Vies::vatPrefix() (PR NeoRazorX/facturascripts#2059) se podrá usar ese.
     */
    protected static function vatPrefix(string $iso): string
    {
        return $iso === 'GR' ? 'EL' : $iso;
    }

    public static function formatAmount(float $amount, int $length, int $align): string
    {
        $signed = ($amount < 0.00) ? 'N' : ' ';
        // forzamos al formato de 2 decimales sin signo y sin separador de decimales
        // nota las funciones intval y cast de (int) no funcionan correctamente si no se hace el round() sin decimales
        $amount = (int)round(abs($amount) * 100.00);
        return $signed . self::formatString($amount, $length - 1, '0', $align);
    }

    public static function formatOnlyNumber(?string $number): string
    {
        if (empty($number)) {
            return '';
        }

        // eliminamos cualquier carácter que no sea un número
        return preg_replace('/[^0-9]/', '', $number);
    }

    public static function formatString(string $string, int $length, string $charter, int $align): string
    {
        // eliminamos los acentos y caracteres especiales
        $string = self::sanitize($string);

        // pasamos el string a mayúsculas
        $string = mb_strtoupper($string, 'UTF-8');

        // limitamos el tamaño del string (por caracteres, no bytes)
        $string = self::limitString($string, $length);

        // rellenamos con el carácter indicado hasta el tamaño indicado, según la alineación indicada
        // usamos mb_strlen para contar caracteres (no bytes) y evitar problemas con ñ, etc.
        $currentLength = mb_strlen($string, 'UTF-8');
        $padLength = $length - $currentLength;
        if ($padLength > 0) {
            $pad = str_repeat($charter, $padLength);
            if ($align === STR_PAD_LEFT) {
                $string = $pad . $string;
            } else {
                $string = $string . $pad;
            }
        }
        return $string;
    }

    protected static function loadCompany(): void
    {
        self::$company = new Empresa();
        self::$company->load(self::$exercise->idempresa);
    }

    protected static function getJustificante(): string
    {
        // Si el usuario ha proporcionado un justificante, lo usamos (ya validado en el controlador)
        if (!empty(self::$justificante)) {
            return self::formatString(self::$justificante, 13, '0', STR_PAD_LEFT);
        }

        // La AEAT exige que el número de justificante no sea todo ceros para presentación por fichero.
        // Formato: modelo (347) + ejercicio (4 dígitos) + secuencial (6 dígitos), p.ej. 3472025000001
        $year = date('Y', strtotime(self::$exercise->fechainicio));
        return '347' . $year . '000001';
    }

    protected static function getCompanyData(): string
    {
        return '1' // TIPO DE REGISTRO
            . '347' // MODELO DECLARACIÓN
            . date('Y', strtotime(self::$exercise->fechainicio)) // EJERCICIO
            . self::formatString(self::$company->cifnif, 9, '0', STR_PAD_LEFT) // NIF DEL DECLARANTE
            . self::formatString(self::$company->nombre, 40, ' ', STR_PAD_RIGHT) // APELLIDOS Y NOMBRE, RAZÓN SOCIAL O DENOMINACIÓN DEL DECLARANTE
            . 'T' // TIPO DE SOPORTE
            . self::formatString(self::formatOnlyNumber(self::$company->telefono1), 9, '0', STR_PAD_LEFT)
            . self::formatString(self::$company->administrador, 40, ' ', STR_PAD_RIGHT) // PERSONA CON QUIÉN RELACIONARSE
            . self::getJustificante() // NÚMERO IDENTIFICATIVO DE LA DECLARACIÓN
            . self::formatString('', 1, ' ', STR_PAD_LEFT)
            . self::formatString(self::$declarationType, 1, ' ', STR_PAD_RIGHT) // DECLARACIÓN COMPLEMENTARIA O SUSTITUTIVA
            . self::formatString(self::$justificanteAnterior, 13, '0', STR_PAD_LEFT) // NÚMERO IDENTIFICATIVO DE LA DECLARACIÓN ANTERIOR
            . self::formatString((string)(count(self::$customersData) + count(self::$suppliersData)), 9, '0', STR_PAD_LEFT) // NÚMERO TOTAL DE PERSONAS Y ENTIDADES
            . self::formatAmount(self::$total, 16, STR_PAD_LEFT) // IMPORTE TOTAL ANUAL DE LAS OPERACIONES
            . self::formatString('', 9, '0', STR_PAD_LEFT) // NÚMERO TOTAL DE INMUEBLES
            . self::formatAmount(0.00, 16, STR_PAD_LEFT) // IMPORTE TOTAL ANUAL DE LAS OPERACIONES DE ARRENDAMIENTO DE LOCALES DE NEGOCIO
            . self::formatString('', 205, ' ', STR_PAD_LEFT) // BLANCOS
            . self::formatString('', 9, ' ', STR_PAD_RIGHT) // NIF DEL REPRESENTANTE LEGAL
            . self::formatString('', 88, ' ', STR_PAD_LEFT) // BLANCOS
            . self::formatString('', 13, ' ', STR_PAD_LEFT); // SELLO ELECTRÓNICO
    }

    protected static function getCustomerData(): string
    {
        $txt = '';
        foreach (self::$customersData as $item) {
            self::$total += (float)$item['total'];

            $txt .= "\n"
                . '2' // TIPO DE REGISTRO
                . '347' // MODELO DECLARACIÓN
                . date('Y', strtotime(self::$exercise->fechainicio)) // EJERCICIO
                . self::formatString(self::$company->cifnif, 9, '0', STR_PAD_LEFT) // NIF DEL DECLARANTE
                . self::checkCifNif($item) // NIF DEL DECLARADO
                . self::formatString('', 9, ' ', STR_PAD_RIGHT) // NIF DEL REPRESENTANTE LEGAL
                . self::formatString($item['cliente'], 40, ' ', STR_PAD_RIGHT) // APELLIDOS Y NOMBRE, RAZÓN SOCIAL O DENOMINACIÓN DEL DECLARADO
                . 'D' // TIPO DE HOJA
                . self::getProvincia($item['provincia']) . self::getPais($item['codpais']) // CÓDIGO PROVINCIA/PAIS
                . ' ' // BLANCOS
                . 'B' // CLAVE OPERACIÓN
                . self::formatAmount($item['total'], 16, STR_PAD_LEFT) // IMPORTE ANUAL DE LAS OPERACIONES
                . ' ' // OPERACIÓN SEGURO
                . ' ' // ARRENDAMIENTO LOCAL NEGOCIO
                . self::formatString('', 15, '0', STR_PAD_LEFT) // IMPORTE PERCIBIDO EN METÁLICO
                . self::formatAmount(0.00, 16, STR_PAD_LEFT) // IMPORTE ANUAL PERCIBIDO POR TRANSMISIONES DE INMUEBLES SUJETAS A IVA
                . self::formatString('', 4, '0', STR_PAD_LEFT) // EJERCICIO
                . self::formatAmount($item['t1'], 16, STR_PAD_LEFT) // IMPORTE DE LAS OPERACIONES PRIMER TRIMESTRE
                . self::formatAmount(0.00, 16, STR_PAD_LEFT) // IMPORTE PERCIBIDO POR TRANSMISIONES DE INMUEBLES SUJETAS A IVA PRIMER TRIMESTRE
                . self::formatAmount($item['t2'], 16, STR_PAD_LEFT) // IMPORTE DE LAS OPERACIONES SEGUNDO TRIMESTRE
                . self::formatAmount(0.00, 16, STR_PAD_LEFT) // IMPORTE PERCIBIDO POR TRANSMISIONES DE INMUEBLES SUJETAS A IVA SEGUNDO TRIMESTRE
                . self::formatAmount($item['t3'], 16, STR_PAD_LEFT) // IMPORTE DE LAS OPERACIONES TERCER TRIMESTRE
                . self::formatAmount(0.00, 16, STR_PAD_LEFT) // IMPORTE PERCIBIDO POR TRANSMISIONES DE INMUEBLES SUJETAS A IVA TERCER TRIMESTRE
                . self::formatAmount($item['t4'], 16, STR_PAD_LEFT) // IMPORTE DE LAS OPERACIONES CUARTO TRIMESTRE
                . self::formatAmount(0.00, 16, STR_PAD_LEFT) // IMPORTE PERCIBIDO POR TRANSMISIONES DE INMUEBLES SUJETAS A IVA CUARTO TRIMESTRE
                . self::getNifOperadorComunitario($item) // NIF OPERADOR COMUNITARIO
                . ' ' // OPERACIONES RÉGIMEN ESPECIAL CRITERIO DE CAJA IVA
                . ' ' // OPERACIÓN CON INVERSIÓN DEL SUJETO PASIVO
                . ' ' // OPERACIÓN CON BIENES VINCULADOS O DESTINADOS A VINCULARSE AL RÉGIMEN DE DEPÓSITO DISTINTO DEL ADUANERO
                . self::formatAmount(0.00, 16, STR_PAD_LEFT) // IMPORTE ANUAL DE LAS OPERACIONES DEVENGADAS CONFORME AL CRITERIO DE CAJA DEL IVA
                . self::formatString('000000', 6, '0', STR_PAD_LEFT) // NUMERO DE CONVOCATORIA BDNS
                . self::formatString('', 195, ' ', STR_PAD_LEFT); // BLANCOS
        }
        return $txt;
    }

    protected static function loadExercise(string $codejercicio): void
    {
        self::$exercise = new Ejercicio();
        self::$exercise->load($codejercicio);
    }

    protected static function getPais(string $codpais): string
    {
        $pais = Paises::get($codpais);
        if (!empty($pais->codiso) && strtoupper($pais->codiso) !== 'ES') {
            return self::formatString($pais->codiso, 2, ' ', STR_PAD_LEFT);
        }

        return self::formatString('', 2, ' ', STR_PAD_LEFT);
    }

    public static function getProvincia(?string $provincia): string
    {
        if (empty($provincia)) {
            return '99';
        }

        $provincia = trim(self::sanitize($provincia));
        $provincia = mb_strtolower($provincia, 'UTF-8');

        switch ($provincia) {
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
            self::$total += (float)$item['total'];

            $txt .= "\n"
                . '2' // TIPO DE REGISTRO
                . '347' // MODELO DECLARACIÓN
                . date('Y', strtotime(self::$exercise->fechainicio)) // EJERCICIO
                . self::formatString(self::$company->cifnif, 9, '0', STR_PAD_LEFT) // NIF DEL DECLARANTE
                . self::checkCifNif($item) // NIF DEL DECLARADO
                . self::formatString('', 9, ' ', STR_PAD_RIGHT) // NIF DEL REPRESENTANTE LEGAL
                . self::formatString($item['proveedor'], 40, ' ', STR_PAD_RIGHT) // APELLIDOS Y NOMBRE, RAZÓN SOCIAL O DENOMINACIÓN DEL DECLARADO
                . 'D' // TIPO DE HOJA
                . self::getProvincia($item['provincia']) . self::getPais($item['codpais']) // CÓDIGO PROVINCIA/PAIS
                . ' ' // BLANCOS
                . 'A' // CLAVE OPERACIÓN
                . self::formatAmount($item['total'], 16, STR_PAD_LEFT) // IMPORTE ANUAL DE LAS OPERACIONES
                . ' ' // OPERACIÓN SEGURO
                . ' ' // ARRENDAMIENTO LOCAL NEGOCIO
                . self::formatString('', 15, '0', STR_PAD_LEFT) // IMPORTE PERCIBIDO EN METÁLICO
                . self::formatAmount(0.00, 16, STR_PAD_LEFT) // IMPORTE ANUAL PERCIBIDO POR TRANSMISIONES DE INMUEBLES SUJETAS A IVA
                . self::formatString('', 4, '0', STR_PAD_LEFT) // EJERCICIO
                . self::formatAmount($item['t1'], 16, STR_PAD_LEFT) // IMPORTE DE LAS OPERACIONES PRIMER TRIMESTRE
                . self::formatAmount(0.00, 16, STR_PAD_LEFT) // IMPORTE PERCIBIDO POR TRANSMISIONES DE INMUEBLES SUJETAS A IVA PRIMER TRIMESTRE
                . self::formatAmount($item['t2'], 16, STR_PAD_LEFT) // IMPORTE DE LAS OPERACIONES SEGUNDO TRIMESTRE
                . self::formatAmount(0.00, 16, STR_PAD_LEFT) // IMPORTE PERCIBIDO POR TRANSMISIONES DE INMUEBLES SUJETAS A IVA SEGUNDO TRIMESTRE
                . self::formatAmount($item['t3'], 16, STR_PAD_LEFT) // IMPORTE DE LAS OPERACIONES TERCER TRIMESTRE
                . self::formatAmount(0.00, 16, STR_PAD_LEFT) // IMPORTE PERCIBIDO POR TRANSMISIONES DE INMUEBLES SUJETAS A IVA TERCER TRIMESTRE
                . self::formatAmount($item['t4'], 16, STR_PAD_LEFT) // IMPORTE DE LAS OPERACIONES CUARTO TRIMESTRE
                . self::formatAmount(0.00, 16, STR_PAD_LEFT) // IMPORTE PERCIBIDO POR TRANSMISIONES DE INMUEBLES SUJETAS A IVA CUARTO TRIMESTRE
                . self::getNifOperadorComunitario($item) // NIF OPERADOR COMUNITARIO
                . ' ' // OPERACIONES RÉGIMEN ESPECIAL CRITERIO DE CAJA IVA
                . ' ' // OPERACIÓN CON INVERSIÓN DEL SUJETO PASIVO
                . ' ' // OPERACIÓN CON BIENES VINCULADOS O DESTINADOS A VINCULARSE AL RÉGIMEN DE DEPÓSITO DISTINTO DEL ADUANERO
                . self::formatAmount(0.00, 16, STR_PAD_LEFT) // IMPORTE ANUAL DE LAS OPERACIONES DEVENGADAS CONFORME AL CRITERIO DE CAJA DEL IVA
                . self::formatString('000000', 6, '0', STR_PAD_LEFT) // NUMERO DE CONVOCATORIA BDNS
                . self::formatString('', 195, ' ', STR_PAD_LEFT); // BLANCOS
        }
        return $txt;
    }

    protected static function limitString(string $string, int $length): string
    {
        return mb_substr($string, 0, $length, 'UTF-8');
    }

    public static function sanitize(?string $txt): string
    {
        $changes = ['/à/' => 'a', '/á/' => 'a', '/â/' => 'a', '/ã/' => 'a', '/ä/' => 'a',
            '/å/' => 'a', '/æ/' => 'ae', '/ç/' => 'c', '/è/' => 'e', '/é/' => 'e', '/ê/' => 'e',
            '/ë/' => 'e', '/ì/' => 'i', '/í/' => 'i', '/î/' => 'i', '/ï/' => 'i', '/ð/' => 'd',
            '/ò/' => 'o', '/ó/' => 'o', '/ô/' => 'o', '/õ/' => 'o', '/ö/' => 'o',
            '/ő/' => 'o', '/ø/' => 'o', '/ù/' => 'u', '/ú/' => 'u', '/û/' => 'u', '/ü/' => 'u',
            '/ű/' => 'u', '/ý/' => 'y', '/þ/' => 'th', '/ÿ/' => 'y',
            '/&quot;/' => '-', '/´/' => '/\'/', '/€/' => 'EUR', '/º/' => '.',
            '/À/' => 'A', '/Á/' => 'A', '/Â/' => 'A', '/Ä/' => 'A',
            '/Ç/' => 'C', '/È/' => 'E', '/É/' => 'E', '/Ê/' => 'E',
            '/Ë/' => 'E', '/Ì/' => 'I', '/Í/' => 'I', '/Î/' => 'I', '/Ï/' => 'I',
            '/Ò/' => 'O', '/Ó/' => 'O', '/Ô/' => 'O', '/Ö/' => 'O',
            '/Ù/' => 'U', '/Ú/' => 'U', '/Û/' => 'U', '/Ü/' => 'U',
            '/Ý/' => 'Y', '/Ÿ/' => 'Y'
        ];

        $txtNoHtml = Tools::noHtml($txt) ?? '';
        return preg_replace(array_keys($changes), $changes, $txtNoHtml);
    }
}
