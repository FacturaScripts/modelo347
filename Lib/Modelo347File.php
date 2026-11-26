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
use FacturaScripts\Dinamic\Lib\Vies;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\Empresa;

/**
 * Genera el fichero de presentación del modelo 347 (declaración anual de operaciones con terceras personas)
 * según el diseño de registro de la AEAT: un registro de declarante (tipo 1) seguido de un registro por
 * cliente y por proveedor declarado (tipo 2), en ISO-8859-1.
 */
class Modelo347File
{
    /** @var Empresa */
    /**
     * Empresa declarante, la del ejercicio.
     *
     * @var Empresa
     */
    protected static $company;

    /** @var array */
    /**
     * Clientes a declarar (filas de Modelo347::$customersData).
     *
     * @var array
     */
    protected static $customersData;

    /** @var Ejercicio */
    /**
     * Ejercicio declarado; de él salen la empresa y el año.
     *
     * @var Ejercicio
     */
    protected static $exercise;

    /** @var array */
    /**
     * Proveedores a declarar (filas de Modelo347::$suppliersData).
     *
     * @var array
     */
    protected static $suppliersData;

    /** @var string */
    /**
     * Tipo de declaración: vacío (normal), 'C' (complementaria) o 'S' (sustitutiva).
     *
     * @var string
     */
    protected static $declarationType = '';

    /** @var string */
    /**
     * Número de justificante indicado por el usuario; si está vacío se genera.
     *
     * @var string
     */
    protected static $justificante = '';

    /** @var string */
    /**
     * Justificante de la declaración anterior, para las complementarias y sustitutivas.
     *
     * @var string
     */
    protected static $justificanteAnterior = '';

    /** @var float */
    /**
     * Importe total anual de las operaciones, que se acumula al generar los registros de tipo 2.
     *
     * @var float
     */
    protected static $total = 0.0;

    /**
     * Devuelve el contenido del fichero del modelo 347 en ISO-8859-1.
     *
     * @param string $codejercicio ejercicio declarado (determina la empresa y el año)
     * @param array $customersData clientes que superan el importe, de Modelo347::$customersData
     * @param array $suppliersData proveedores que superan el importe, de Modelo347::$suppliersData
     * @param string $justificante número de justificante; si está vacío se genera
     * @param string $declarationType tipo de declaración: '' (normal), 'C' (complementaria) o 'S' (sustitutiva)
     * @param string $justificanteAnterior justificante de la declaración anterior (complementaria o sustitutiva)
     *
     * @return string
     */
    public static function generate(string $codejercicio, array $customersData, array $suppliersData, string $justificante = '', string $declarationType = '', string $justificanteAnterior = ''): string
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

    /**
     * Devuelve el NIF del declarado (9 posiciones): en blanco si el tercero no es español y, si lo es, el CIF/NIF con ceros a la izquierda.
     *
     * @param array $item fila del cliente o proveedor.
     *
     * @return string
     */
    protected static function checkCifNif(array $item): string
    {
        if (false === self::isSpanish($item['codpais'] ?? '')) {
            return self::formatString('', 9, ' ', STR_PAD_RIGHT);
        }

        return self::formatString($item['cifnif'], 9, '0', STR_PAD_LEFT);
    }

    /**
     * Indica si un país es España, comparando su código ISO de dos letras.
     *
     * @param string $codpais código de país del modelo (Alpha-3).
     *
     * @return bool
     */
    protected static function isSpanish(string $codpais): bool
    {
        $pais = Paises::get($codpais);
        return strtoupper($pais->codiso ?? '') === 'ES';
    }

    /**
     * NIF del operador comunitario (17 caracteres): las dos primeras letras de su NIF-IVA y el número. Solo para
     * los de otros Estados miembros; el resto, en blanco. El prefijo coincide con el código ISO salvo en Grecia
     * (EL en lugar de GR), según Vies::vatPrefix() del core, y del número se quitan los dos.
     */
    protected static function getNifOperadorComunitario(array $item): string
    {
        $codpais = $item['codpais'] ?? '';
        if (false === self::isSpanish($codpais) && !empty($item['cifnif']) && Paises::miembroUE($codpais)) {
            $pais = Paises::get($codpais);
            $iso = strtoupper($pais->codiso ?? '');
            $prefix = Vies::vatPrefix($iso);
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
     * Formatea un importe para el fichero: primer carácter 'N' si es negativo (o blanco), seguido de los céntimos sin separador decimal y con ceros a la izquierda.
     *
     * @param float $amount importe a formatear.
     * @param int   $length longitud total del campo, incluido el signo.
     * @param int   $align  alineación del relleno (STR_PAD_LEFT o STR_PAD_RIGHT).
     *
     * @return string
     */
    public static function formatAmount(float $amount, int $length, int $align): string
    {
        $signed = ($amount < 0.00) ? 'N' : ' ';
        // forzamos al formato de 2 decimales sin signo y sin separador de decimales
        // nota las funciones intval y cast de (int) no funcionan correctamente si no se hace el round() sin decimales
        $amount = (int)round(abs($amount) * 100.00);
        return $signed . self::formatString($amount, $length - 1, '0', $align);
    }

    /**
     * Elimina de un texto todo lo que no sea un dígito.
     *
     * @param string|null $number texto original, por ejemplo un teléfono.
     *
     * @return string cadena vacía si no se indica.
     */
    public static function formatOnlyNumber(?string $number): string
    {
        if (empty($number)) {
            return '';
        }

        // eliminamos cualquier carácter que no sea un número
        return preg_replace('/[^0-9]/', '', $number);
    }

    /**
     * Prepara un texto para un campo de posición fija: sin acentos ni HTML, en mayúsculas, recortado por caracteres (no por bytes) y rellenado hasta la longitud indicada.
     *
     * @param string $string  texto original.
     * @param int    $length  longitud del campo.
     * @param string $charter carácter de relleno.
     * @param int    $align   alineación del relleno (STR_PAD_LEFT o STR_PAD_RIGHT).
     *
     * @return string
     */
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

    /**
     * Carga la empresa del ejercicio declarado.
     */
    protected static function loadCompany(): void
    {
        self::$company = new Empresa();
        self::$company->load(self::$exercise->idempresa);
    }

    /**
     * Devuelve el número de justificante de 13 posiciones: el indicado por el usuario o, si no hay, uno generado (modelo + año + secuencial), porque la AEAT rechaza el justificante a ceros en ficheros.
     *
     * @return string
     */
    protected static function getJustificante(): string
    {
        // si el usuario ha indicado un justificante, lo usamos: el controlador ya ha comprobado que tiene 13 dígitos y empieza por 347
        if (!empty(self::$justificante)) {
            return self::formatString(self::$justificante, 13, '0', STR_PAD_LEFT);
        }

        // La AEAT exige que el número de justificante no sea todo ceros para presentación por fichero.
        // Formato: modelo (347) + ejercicio (4 dígitos) + secuencial (6 dígitos), p.ej. 3472025000001
        $year = date('Y', strtotime(self::$exercise->fechainicio));
        return '347' . $year . '000001';
    }

    /**
     * Construye el registro de tipo 1 (declarante).
     *
     * @return string
     */
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

    /**
     * Construye los registros de tipo 2 (hoja D) de los clientes, con clave de operación 'B', y acumula su importe en el total.
     *
     * @return string cada registro va precedido de un salto de línea.
     */
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

    /**
     * Carga el ejercicio declarado.
     *
     * @param string $codejercicio código del ejercicio.
     */
    protected static function loadExercise(string $codejercicio): void
    {
        self::$exercise = new Ejercicio();
        self::$exercise->load($codejercicio);
    }

    /**
     * Devuelve el código de país del registro (2 posiciones): en blanco para España y el código ISO para el resto.
     *
     * @param string $codpais código de país del modelo.
     *
     * @return string
     */
    protected static function getPais(string $codpais): string
    {
        $pais = Paises::get($codpais);
        if (!empty($pais->codiso) && strtoupper($pais->codiso) !== 'ES') {
            return self::formatString($pais->codiso, 2, ' ', STR_PAD_LEFT);
        }

        return self::formatString('', 2, ' ', STR_PAD_LEFT);
    }

    /**
     * Traduce el nombre de una provincia española a su código de dos cifras.
     *
     * @param string|null $provincia nombre de la provincia, con o sin acentos.
     *
     * @return string '99' si está vacía o no se reconoce.
     */
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

    /**
     * Construye los registros de tipo 2 (hoja D) de los proveedores, con clave de operación 'A', y acumula su importe en el total.
     *
     * @return string cada registro va precedido de un salto de línea.
     */
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

    /**
     * Recorta un texto a un número de caracteres (no de bytes).
     *
     * @param string $string texto original.
     * @param int    $length máximo de caracteres.
     *
     * @return string
     */
    protected static function limitString(string $string, int $length): string
    {
        return mb_substr($string, 0, $length, 'UTF-8');
    }

    /**
     * Quita el HTML y sustituye las letras acentuadas y otros caracteres especiales por su equivalente simple. La Ñ se conserva.
     *
     * @param string|null $txt texto original.
     *
     * @return string
     */
    public static function sanitize(?string $txt): string
    {
        $changes = ['/à/' => 'a', '/á/' => 'a', '/â/' => 'a', '/ã/' => 'a', '/ä/' => 'a',
            '/å/' => 'a', '/æ/' => 'ae', '/ç/' => 'c', '/è/' => 'e', '/é/' => 'e', '/ê/' => 'e',
            '/ë/' => 'e', '/ì/' => 'i', '/í/' => 'i', '/î/' => 'i', '/ï/' => 'i', '/ð/' => 'd',
            '/ò/' => 'o', '/ó/' => 'o', '/ô/' => 'o', '/õ/' => 'o', '/ö/' => 'o',
            '/ő/' => 'o', '/ø/' => 'o', '/ù/' => 'u', '/ú/' => 'u', '/û/' => 'u', '/ü/' => 'u',
            '/ű/' => 'u', '/ý/' => 'y', '/þ/' => 'th', '/ÿ/' => 'y',
            '/&quot;/' => '-', '/&#39;/' => "'", '/´/' => "'", '/€/' => 'EUR', '/º/' => '.',
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
