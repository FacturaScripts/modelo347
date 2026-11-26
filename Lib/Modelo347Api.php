<?php
/**
 * This file is part of Modelo347 plugin for FacturaScripts
 * Copyright (C) 2026 Carlos Garcia Gomez <carlos@facturascripts.com>
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

use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\Modelo347 as Modelo347Lib;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\Empresa;

/**
 * Construye la respuesta del endpoint de solo lectura GET /api/3/modelo347.
 *
 * Resuelve la empresa y el ejercicio a partir de los parámetros de la petición, ejecuta el mismo
 * cálculo que la pantalla del plugin (Lib/Modelo347) y devuelve los clientes y proveedores a declarar,
 * sus totales, las casillas del resumen y los avisos. No crea, modifica ni borra ningún registro.
 *
 * Parámetros (todos opcionales, como texto de la query string):
 *   - codejercicio: ejercicio a declarar. Si se indica, manda sobre idempresa y anio.
 *   - idempresa: empresa (por defecto, la predeterminada).
 *   - anio: año natural del ejercicio de esa empresa (por defecto, el actual).
 *   - periodo: solo ANUAL (por defecto ANUAL).
 *   - importe: importe mínimo anual de operaciones (por defecto 3005.06).
 *   - origen: facturas (por defecto) o contabilidad.
 *   - agrupar: tercero (por defecto) o cifnif.
 *   - excluir_irpf: 1/true para excluir las facturas con retención de IRPF.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class Modelo347Api
{
    /** Agrupaciones de la API y su equivalente en Lib/Modelo347. */
    const GROUPINGS = [
        'tercero' => Modelo347Lib::GROUP_SUBJECT,
        'cifnif' => Modelo347Lib::GROUP_CIFNIF,
    ];

    /** Número del modelo. */
    const MODEL = '347';

    /** Orígenes de datos de la API y su equivalente en Lib/Modelo347. */
    const ORIGINS = [
        'facturas' => Modelo347Lib::EXAMINE_INVOICES,
        'contabilidad' => Modelo347Lib::EXAMINE_ACCOUNTING,
    ];

    /** Periodos que admite el modelo. */
    const PERIODS = ['ANUAL'];

    /** Nombre del plugin, para obtener su versión. */
    const PLUGIN_NAME = 'Modelo347';

    /**
     * Construye la respuesta del endpoint a partir de los parámetros de la petición.
     *
     * @param array $params parámetros de la query string (valores string o null si no se indican).
     *
     * @return array ['code' => código HTTP, 'body' => cuerpo JSON de la respuesta].
     */
    public static function build(array $params): array
    {
        // periodo: solo anual, y si no se indica se usa el anual
        $rawPeriod = $params['periodo'] ?? null;
        $periodo = self::isEmptyParam($rawPeriod) ? 'ANUAL' : self::normalizePeriod($rawPeriod);
        if (false === in_array($periodo, self::PERIODS, true)) {
            return self::error(400, 'invalid-period', ['allowed' => self::PERIODS]);
        }

        // parámetros propios del modelo
        $importe = Modelo347Lib::DEFAULT_AMOUNT;
        if (false === self::isEmptyParam($params['importe'] ?? null)) {
            $value = str_replace(',', '.', trim((string)$params['importe']));
            if (false === is_numeric($value) || (float)$value < 0) {
                return self::error(400, 'invalid-parameter', ['parameter' => 'importe']);
            }
            $importe = (float)$value;
        }

        $origen = self::isEmptyParam($params['origen'] ?? null) ? 'facturas' : strtolower(trim($params['origen']));
        if (false === isset(self::ORIGINS[$origen])) {
            return self::error(400, 'invalid-parameter', ['parameter' => 'origen', 'allowed' => array_keys(self::ORIGINS)]);
        }

        $agrupar = self::isEmptyParam($params['agrupar'] ?? null) ? 'tercero' : strtolower(trim($params['agrupar']));
        if (false === isset(self::GROUPINGS[$agrupar])) {
            return self::error(400, 'invalid-parameter', ['parameter' => 'agrupar', 'allowed' => array_keys(self::GROUPINGS)]);
        }

        $excluirIrpf = self::parseBool($params['excluir_irpf'] ?? null);
        if (null === $excluirIrpf) {
            return self::error(400, 'invalid-parameter', ['parameter' => 'excluir_irpf']);
        }

        // empresa y ejercicio
        $resolved = self::resolveExercise($params['codejercicio'] ?? null, $params['idempresa'] ?? null, $params['anio'] ?? null);
        if (isset($resolved['error'])) {
            return $resolved['error'];
        }

        /** @var Ejercicio $exercise */
        $exercise = $resolved['exercise'];
        /** @var Empresa $company */
        $company = $resolved['company'];

        // mismo cálculo que la pantalla del plugin
        $model347 = new Modelo347Lib($exercise->codejercicio, $importe, self::ORIGINS[$origen], self::GROUPINGS[$agrupar], $excluirIrpf);
        $model347->calculate();

        $customers = self::detailRows($model347->customersData, 'cliente');
        $suppliers = self::detailRows($model347->suppliersData, 'proveedor');

        $totalCustomers = round((float)$model347->customersTotals['total'], 2);
        $totalSuppliers = round((float)$model347->suppliersTotals['total'], 2);

        $resumen = [
            'num_clientes' => count($customers),
            'num_proveedores' => count($suppliers),
            'total_clientes' => $totalCustomers,
            'total_proveedores' => $totalSuppliers,
        ];
        foreach (['t1', 't2', 't3', 't4'] as $quarter) {
            $resumen['clientes_' . $quarter] = round((float)$model347->customersTotals[$quarter], 2);
        }
        foreach (['t1', 't2', 't3', 't4'] as $quarter) {
            $resumen['proveedores_' . $quarter] = round((float)$model347->suppliersTotals[$quarter], 2);
        }

        return [
            'code' => 200,
            'body' => [
                'modelo' => self::MODEL,
                'plugin_version' => self::pluginVersion(),
                'empresa' => self::companyData($company),
                'ejercicio' => self::exerciseData($exercise),
                'periodo' => $periodo,
                'fechainicio' => self::isoDate($exercise->fechainicio),
                'fechafin' => self::isoDate($exercise->fechafin),
                'parametros' => [
                    'importe' => round($importe, 2),
                    'origen' => $origen,
                    'agrupar' => $agrupar,
                    'excluir_irpf' => $excluirIrpf,
                ],
                'resumen' => $resumen,
                // casillas del resumen de la declaración: 01 nº total de personas y entidades,
                // 02 importe total anual de las operaciones (las mismas del registro de tipo 1 del TXT)
                'casillas' => [
                    '01' => count($customers) + count($suppliers),
                    '02' => round($totalCustomers + $totalSuppliers, 2),
                ],
                'resultado' => null,
                'detalle' => [
                    'clientes' => $customers,
                    'proveedores' => $suppliers,
                ],
                'avisos' => self::warnings($model347, $customers, $suppliers),
            ],
        ];
    }

    /**
     * Normaliza un periodo: mayúsculas, sin espacios y con los alias admitidos
     * (1T-4T → T1-T4, 1-12 → 01-12, ANNUAL/Y/A/0A → ANUAL).
     *
     * @param string|null $periodo periodo tal y como llega en la petición.
     *
     * @return string periodo normalizado, o cadena vacía si no se indica.
     */
    public static function normalizePeriod(?string $periodo): string
    {
        $value = strtoupper(preg_replace('/\s+/', '', (string)$periodo));
        if (in_array($value, ['ANUAL', 'ANNUAL', 'Y', 'A', '0A'], true)) {
            return 'ANUAL';
        }

        if (preg_match('/^([1-4])T$/', $value, $matches)) {
            return 'T' . $matches[1];
        }

        if (preg_match('/^\d{1,2}$/', $value) && (int)$value >= 1 && (int)$value <= 12) {
            return sprintf('%02d', (int)$value);
        }

        return $value;
    }

    /**
     * Resuelve el ejercicio y la empresa: por código de ejercicio o, si no se indica, por empresa y año
     * (el ejercicio de la empresa cuya fecha de inicio cae en ese año).
     *
     * @param string|null $codejercicio código del ejercicio.
     * @param string|null $idempresa    empresa; por defecto, la predeterminada.
     * @param string|null $anio         año natural; por defecto, el actual.
     *
     * @return array ['exercise' => Ejercicio, 'company' => Empresa] o ['error' => respuesta de error].
     */
    public static function resolveExercise(?string $codejercicio, ?string $idempresa, ?string $anio): array
    {
        // el código de ejercicio manda: la empresa es la del ejercicio
        if (false === self::isEmptyParam($codejercicio)) {
            $exercise = new Ejercicio();
            if (false === $exercise->load(trim($codejercicio))) {
                return ['error' => self::error(404, 'exercise-not-found', ['codejercicio' => trim($codejercicio)])];
            }

            $company = new Empresa();
            if (false === $company->load($exercise->idempresa)) {
                return ['error' => self::error(404, 'company-not-found', ['idempresa' => $exercise->idempresa])];
            }

            return ['exercise' => $exercise, 'company' => $company];
        }

        // empresa indicada o predeterminada
        $id = self::isEmptyParam($idempresa) ? (string)Tools::settings('default', 'idempresa') : trim($idempresa);
        $company = new Empresa();
        if (false === ctype_digit($id) || false === $company->load((int)$id)) {
            return ['error' => self::error(404, 'company-not-found', ['idempresa' => $id])];
        }

        // año indicado o actual
        $year = self::isEmptyParam($anio) ? date('Y') : trim($anio);
        if (1 !== preg_match('/^\d{4}$/', $year)) {
            return ['error' => self::error(400, 'invalid-year', ['anio' => $year])];
        }

        // ejercicio de la empresa cuya fecha de inicio cae en ese año
        $where = [Where::eq('idempresa', $company->idempresa)];
        foreach (Ejercicio::all($where, ['fechainicio' => 'ASC'], 0, 0) as $exercise) {
            if (date('Y', strtotime($exercise->fechainicio)) === $year) {
                return ['exercise' => $exercise, 'company' => $company];
            }
        }

        return ['error' => self::error(404, 'exercise-not-found', ['idempresa' => $company->idempresa, 'anio' => $year])];
    }

    /**
     * Datos básicos de la empresa para la respuesta.
     *
     * @param Empresa $company empresa declarante.
     *
     * @return array
     */
    protected static function companyData(Empresa $company): array
    {
        return [
            'idempresa' => (int)$company->idempresa,
            'nombre' => $company->nombre,
            'cifnif' => $company->cifnif,
        ];
    }

    /**
     * Convierte las filas del cálculo en filas planas para el detalle de la respuesta.
     *
     * @param array  $data      clientes o proveedores del cálculo, indexados por código.
     * @param string $nameField campo con el nombre del tercero ('cliente' o 'proveedor').
     *
     * @return array
     */
    protected static function detailRows(array $data, string $nameField): array
    {
        $rows = [];
        foreach ($data as $code => $row) {
            $rows[] = [
                'codigo' => (string)$code,
                'nombre' => $row[$nameField] ?? '',
                'cifnif' => $row['cifnif'] ?? '',
                'codpais' => $row['codpais'] ?? null,
                'codpostal' => $row['codpostal'] ?? '',
                'ciudad' => $row['ciudad'] ?? '',
                'provincia' => $row['provincia'] ?? '',
                't1' => round((float)$row['t1'], 2),
                't2' => round((float)$row['t2'], 2),
                't3' => round((float)$row['t3'], 2),
                't4' => round((float)$row['t4'], 2),
                'total' => round((float)$row['total'], 2),
            ];
        }
        return $rows;
    }

    /**
     * Construye una respuesta de error en el formato de los endpoints del core.
     *
     * @param int    $code    código HTTP.
     * @param string $message clave del error.
     * @param array  $extra   datos adicionales.
     *
     * @return array
     */
    protected static function error(int $code, string $message, array $extra = []): array
    {
        return [
            'code' => $code,
            'body' => array_merge(['status' => 'error', 'message' => $message], $extra),
        ];
    }

    /**
     * Datos básicos del ejercicio para la respuesta, con las fechas en formato ISO.
     *
     * @param Ejercicio $exercise ejercicio declarado.
     *
     * @return array
     */
    protected static function exerciseData(Ejercicio $exercise): array
    {
        return [
            'codejercicio' => $exercise->codejercicio,
            'nombre' => $exercise->nombre,
            'fechainicio' => self::isoDate($exercise->fechainicio),
            'fechafin' => self::isoDate($exercise->fechafin),
            'estado' => $exercise->estado,
        ];
    }

    /**
     * Indica si un parámetro no se ha indicado o está vacío.
     *
     * @param mixed $value valor del parámetro.
     *
     * @return bool
     */
    protected static function isEmptyParam($value): bool
    {
        return null === $value || '' === trim((string)$value);
    }

    /**
     * Convierte una fecha del modelo (d-m-Y) a formato ISO (Y-m-d).
     *
     * @param string|null $date fecha.
     *
     * @return string|null
     */
    protected static function isoDate(?string $date): ?string
    {
        return empty($date) ? null : date('Y-m-d', strtotime($date));
    }

    /**
     * Interpreta un parámetro booleano. Sin valor equivale a false.
     *
     * @param string|null $value valor del parámetro.
     *
     * @return bool|null el valor, o null si no es un booleano válido.
     */
    protected static function parseBool(?string $value): ?bool
    {
        $value = strtolower(trim((string)$value));
        if (in_array($value, ['', '0', 'false', 'no', 'off'], true)) {
            return false;
        }

        if (in_array($value, ['1', 'true', 'si', 'sí', 'yes', 'on'], true)) {
            return true;
        }

        return null;
    }

    /**
     * Devuelve la versión instalada del plugin.
     *
     * @return float
     */
    protected static function pluginVersion(): float
    {
        $plugin = Plugins::get(self::PLUGIN_NAME);
        if (null !== $plugin && $plugin->version > 0) {
            return (float)$plugin->version;
        }

        // respaldo: leemos el facturascripts.ini del plugin
        $ini = parse_ini_file(dirname(__DIR__) . '/facturascripts.ini');
        return (float)($ini['version'] ?? 0);
    }

    /**
     * Avisos para el usuario en español: los mismos que muestra la pantalla y, además,
     * los terceros a declarar sin CIF/NIF.
     *
     * @param Modelo347Lib $model347  cálculo ejecutado.
     * @param array        $customers detalle de clientes.
     * @param array        $suppliers detalle de proveedores.
     *
     * @return string[]
     */
    protected static function warnings(Modelo347Lib $model347, array $customers, array $suppliers): array
    {
        $translator = Tools::lang('es_ES');
        $warnings = [];
        foreach ($model347->checkData('es_ES') as $warning) {
            if ($warning['key'] === '347-no-data') {
                $warnings[] = 'No hay clientes ni proveedores cuyo volumen de operaciones en el ejercicio alcance '
                    . number_format($model347->amount, 2, ',', '.') . ' €: no hay nada que declarar.';
                continue;
            }

            // el tipo de tercero va en mitad de la frase: en minúsculas
            $context = $warning['context'];
            if (isset($context['%type%'])) {
                $context['%type%'] = mb_strtolower($context['%type%']);
            }
            $warnings[] = rtrim($translator->trans($warning['key'], $context), '.') . '.';
        }

        // el CIF/NIF es obligatorio para cada declarado
        foreach ($customers as $row) {
            if (empty($row['cifnif'])) {
                $warnings[] = 'El cliente ' . $row['nombre'] . ' (' . $row['codigo'] . ') no tiene CIF/NIF y es obligatorio para declararlo.';
            }
        }
        foreach ($suppliers as $row) {
            if (empty($row['cifnif'])) {
                $warnings[] = 'El proveedor ' . $row['nombre'] . ' (' . $row['codigo'] . ') no tiene CIF/NIF y es obligatorio para declararlo.';
            }
        }

        return $warnings;
    }
}
