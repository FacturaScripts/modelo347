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

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\DataSrc\Ejercicios;
use FacturaScripts\Core\DataSrc\Empresas;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Cuenta;
use FacturaScripts\Dinamic\Model\Proveedor;

/**
 * Cálculo de los datos del Modelo 347 (declaración anual de operaciones con terceras personas).
 *
 * Obtiene, para un ejercicio, los clientes y proveedores cuyo volumen anual de operaciones alcanza
 * el importe mínimo, desglosado por trimestres, a partir de las facturas o de la contabilidad.
 * Lo usan la pantalla del plugin (vista, Excel, TXT y envío de emails) y el endpoint de la API,
 * para que ambos den exactamente las mismas cifras.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class Modelo347
{
    /** Importe mínimo anual de operaciones que obliga a declarar a un tercero. */
    const DEFAULT_AMOUNT = 3005.06;

    /** Origen de los datos: partidas contables de las subcuentas de clientes y proveedores. */
    const EXAMINE_ACCOUNTING = 'accounting';

    /** Origen de los datos: facturas de venta y compra. */
    const EXAMINE_INVOICES = 'invoices';

    /** Agrupación por CIF/NIF: los terceros con el mismo CIF/NIF se suman en una sola fila. */
    const GROUP_CIFNIF = 'cifnif';

    /** Agrupación por cliente o proveedor: una fila por cada código de tercero. */
    const GROUP_SUBJECT = 'customer-supplier';

    /**
     * Importe mínimo anual de operaciones para incluir a un tercero.
     *
     * @var float
     */
    public $amount = self::DEFAULT_AMOUNT;

    /**
     * Código del ejercicio que se declara.
     *
     * @var string|null
     */
    public $codejercicio;

    /**
     * Clientes a declarar, indexados por código de cliente.
     *
     * @var array
     */
    public $customersData = [];

    /**
     * Totales de los clientes a declarar (fila de totales de la pantalla y del Excel).
     *
     * @var array
     */
    public $customersTotals = [];

    /**
     * Origen de los datos: 'invoices' (facturas) o 'accounting' (contabilidad).
     *
     * @var string
     */
    public $examine = self::EXAMINE_INVOICES;

    /**
     * Si es true, se excluyen las facturas con retención de IRPF.
     *
     * @var bool
     */
    public $excludeIrpf = false;

    /**
     * Agrupación de los terceros: 'customer-supplier' (por código) o 'cifnif' (por CIF/NIF).
     *
     * @var string
     */
    public $grouping = self::GROUP_SUBJECT;

    /**
     * Proveedores y acreedores a declarar, indexados por código de proveedor.
     *
     * @var array
     */
    public $suppliersData = [];

    /**
     * Totales de los proveedores a declarar (fila de totales de la pantalla y del Excel).
     *
     * @var array
     */
    public $suppliersTotals = [];

    /**
     * Conexión a la base de datos.
     *
     * @var DataBase
     */
    protected $dataBase;

    /**
     * @param string|null $codejercicio código del ejercicio que se declara.
     * @param float       $amount       importe mínimo anual de operaciones.
     * @param string      $examine      origen de los datos: 'invoices' o 'accounting'.
     * @param string      $grouping     agrupación: 'customer-supplier' o 'cifnif'.
     * @param bool        $excludeIrpf  true para excluir las facturas con retención de IRPF.
     */
    public function __construct(
        ?string $codejercicio,
        float $amount = self::DEFAULT_AMOUNT,
        string $examine = self::EXAMINE_INVOICES,
        string $grouping = self::GROUP_SUBJECT,
        bool $excludeIrpf = false
    ) {
        $this->codejercicio = $codejercicio;
        $this->amount = $amount;
        $this->examine = $examine;
        $this->grouping = $grouping;
        $this->excludeIrpf = $excludeIrpf;
        $this->dataBase = new DataBase();
    }

    /**
     * Devuelve los orígenes de datos disponibles.
     *
     * @return string[]
     */
    public static function allExamine(): array
    {
        return [self::EXAMINE_ACCOUNTING, self::EXAMINE_INVOICES];
    }

    /**
     * Devuelve las agrupaciones disponibles.
     *
     * @return string[]
     */
    public static function allGroupBy(): array
    {
        return [self::GROUP_CIFNIF, self::GROUP_SUBJECT];
    }

    /**
     * Calcula los clientes y proveedores a declarar y sus totales.
     *
     * @return $this
     */
    public function calculate(): self
    {
        $this->loadCustomersData();
        $this->loadSuppliersData();
        return $this;
    }

    /**
     * Revisa los datos calculados y devuelve los avisos para el usuario: empresa sin administrador
     * o teléfono, declaración sin datos y terceros sin provincia o país.
     *
     * Cada aviso es un array con la clave de traducción ('key') y su contexto ('context'). El tipo de
     * tercero del contexto (%type%) se traduce al idioma indicado.
     *
     * @param string|null $lang idioma para traducir el tipo de tercero; vacío para el idioma por defecto.
     *
     * @return array
     */
    public function checkData(?string $lang = ''): array
    {
        $warnings = [];

        // obtenemos la empresa del ejercicio
        $exerciseModel = Ejercicios::get($this->codejercicio);
        $companyModel = Empresas::get($exerciseModel->idempresa);

        if (empty($companyModel->administrador)) {
            $warnings[] = ['key' => 'company-admin-no-data', 'context' => ['%company%' => $companyModel->nombre]];
        }

        if (empty($companyModel->telefono1)) {
            $warnings[] = ['key' => 'company-phone-no-data', 'context' => ['%company%' => $companyModel->nombre]];
        }

        if (empty($this->customersData) && empty($this->suppliersData)) {
            $warnings[] = ['key' => '347-no-data', 'context' => []];
        }

        foreach ($this->customersData as $data) {
            $warnings = array_merge($warnings, $this->checkAddress($data, 'customer', $lang));
        }

        foreach ($this->suppliersData as $data) {
            $warnings = array_merge($warnings, $this->checkAddress($data, 'supplier', $lang));
        }

        return $warnings;
    }

    /**
     * Carga los clientes que alcanzan el importe mínimo y calcula sus totales.
     */
    public function loadCustomersData(): void
    {
        $this->customersData = $this->examine === self::EXAMINE_INVOICES ?
            $this->getCustomersDataInvoices() :
            $this->getCustomersDataAccounting();

        // excluimos los que no alcanzan el importe mínimo
        foreach ($this->customersData as $key => $row) {
            if ($row['total'] < $this->amount) {
                unset($this->customersData[$key]);
            }
        }

        // totales
        $this->customersTotals = [
            'cifnif' => '',
            'cliente' => '',
            'codpostal' => '',
            'ciudad' => '',
            'provincia' => '',
            't1' => 0.0,
            't2' => 0.0,
            't3' => 0.0,
            't4' => 0.0,
            'total' => 0.0
        ];
        foreach ($this->customersData as $row) {
            $this->customersTotals['t1'] += $row['t1'];
            $this->customersTotals['t2'] += $row['t2'];
            $this->customersTotals['t3'] += $row['t3'];
            $this->customersTotals['t4'] += $row['t4'];
            $this->customersTotals['total'] += $row['total'];
        }
    }

    /**
     * Carga los proveedores que alcanzan el importe mínimo y calcula sus totales.
     */
    public function loadSuppliersData(): void
    {
        $this->suppliersData = $this->examine === self::EXAMINE_INVOICES ?
            $this->getSuppliersDataInvoices() :
            $this->getSuppliersDataAccounting();

        // excluimos los que no alcanzan el importe mínimo
        foreach ($this->suppliersData as $key => $row) {
            if ($row['total'] < $this->amount) {
                unset($this->suppliersData[$key]);
            }
        }

        // totales
        $this->suppliersTotals = [
            'cifnif' => '',
            'proveedor' => '',
            'codpostal' => '',
            'ciudad' => '',
            'provincia' => '',
            't1' => 0.0,
            't2' => 0.0,
            't3' => 0.0,
            't4' => 0.0,
            'total' => 0.0
        ];
        foreach ($this->suppliersData as $row) {
            $this->suppliersTotals['t1'] += $row['t1'];
            $this->suppliersTotals['t2'] += $row['t2'];
            $this->suppliersTotals['t3'] += $row['t3'];
            $this->suppliersTotals['t4'] += $row['t4'];
            $this->suppliersTotals['total'] += $row['total'];
        }
    }

    /**
     * Comprueba que un tercero a declarar tiene provincia y país.
     *
     * @param array       $data fila del cliente o proveedor.
     * @param string      $type 'customer' o 'supplier'.
     * @param string|null $lang idioma para traducir el tipo de tercero.
     *
     * @return array avisos con la clave de traducción y su contexto.
     */
    protected function checkAddress(array $data, string $type, ?string $lang): array
    {
        $warnings = [];
        $context = [
            '%cifnif%' => $data['cifnif'],
            '%name%' => $data['cliente'] ?? $data['proveedor'] ?? '',
            '%type%' => Tools::lang($lang)->trans($type),
        ];

        if (empty($data['provincia'])) {
            $warnings[] = ['key' => '347-no-province', 'context' => $context];
        }

        if (empty($data['codpais'])) {
            $warnings[] = ['key' => '347-no-country', 'context' => $context];
        }

        return $warnings;
    }

    /**
     * Devuelve el importe del debe o del haber de las subcuentas de una cuenta, agrupado por mes,
     * para las fechas del ejercicio. Excluye los asientos especiales (apertura, cierre, regularización).
     *
     * @param Cuenta $cuenta cuenta especial de clientes o proveedores.
     * @param string $column 'debe' o 'haber'.
     *
     * @return array
     */
    protected function getAccountingInfo(Cuenta $cuenta, string $column): array
    {
        $ejercicio = Ejercicios::get($this->codejercicio);

        $dbType = Tools::config('db_type');
        if (strtolower($dbType) == 'postgresql') {
            $sql = "select idsubcuenta, codsubcuenta, to_char(fecha,'FMMM') as mes, sum(" . $column . ") as total from partidas p, asientos a"
                . " where idsubcuenta IN (select idsubcuenta from subcuentas where idcuenta = " . $this->dataBase->var2str($cuenta->idcuenta) . ")"
                . " and p.idasiento = a.idasiento"
                . " and a.operacion is null"
                . " and fecha >= " . $this->dataBase->var2str($ejercicio->fechainicio)
                . " and fecha <= " . $this->dataBase->var2str($ejercicio->fechafin)
                . " group by 1, 2, 3 order by idsubcuenta asc, mes asc;";
        } else {
            $sql = "select idsubcuenta, codsubcuenta, DATE_FORMAT(fecha, '%m') as mes, sum(" . $column . ") as total from partidas p, asientos a"
                . " where idsubcuenta IN (select idsubcuenta from subcuentas where idcuenta = " . $this->dataBase->var2str($cuenta->idcuenta) . ")"
                . " and p.idasiento = a.idasiento"
                . " and a.operacion is null"
                . " and fecha >= " . $this->dataBase->var2str($ejercicio->fechainicio)
                . " and fecha <= " . $this->dataBase->var2str($ejercicio->fechafin)
                . " group by 1, 2, 3 order by idsubcuenta asc, mes asc;";
        }

        return $this->dataBase->select($sql);
    }

    /**
     * Devuelve el código bajo el que se acumula un tercero: el suyo propio o, si se agrupa por CIF/NIF,
     * el del primer tercero encontrado con ese CIF/NIF.
     *
     * @param array  $fiscalNumbers CIF/NIF ya encontrados y su código, se actualiza por referencia.
     * @param string $code          código del cliente o proveedor.
     * @param string $idfiscal      CIF/NIF del tercero.
     *
     * @return string
     */
    protected function getCodeForTotal(array &$fiscalNumbers, string $code, string $idfiscal): string
    {
        if ($this->grouping === self::GROUP_CIFNIF) {
            if (false === isset($fiscalNumbers[$idfiscal])) {
                $fiscalNumbers[$idfiscal] = $code;
            }
            return $fiscalNumbers[$idfiscal];
        }
        return $code;
    }

    /**
     * Obtiene los importes de los clientes a partir de la contabilidad (debe de sus subcuentas).
     *
     * @return array
     */
    protected function getCustomersDataAccounting(): array
    {
        $items = [];
        $fiscalNumbers = [];

        // buscamos las cuentas especiales de clientes de este ejercicio
        $cuentaModel = new Cuenta();
        $where = [
            Where::eq('codejercicio', $this->codejercicio),
            Where::eq('codcuentaesp', 'CLIENT'),
        ];
        foreach ($cuentaModel->all($where, [], 0, 0) as $cuenta) {
            // buscamos las partidas de las subcuentas de esta cuenta
            foreach ($this->getAccountingInfo($cuenta, 'debe') as $row) {
                // buscamos el cliente de la subcuenta
                $cliente = new Cliente();
                $where = [Where::eq('codsubcuenta', $row['codsubcuenta'])];
                if (false === $cliente->loadWhere($where)) {
                    // no se ha encontrado el cliente, saltamos
                    continue;
                }

                $codcliente = $this->getCodeForTotal($fiscalNumbers, $cliente->codcliente, $cliente->cifnif);
                if (isset($items[$codcliente])) {
                    $this->groupTotals($items[$codcliente], $row);
                    continue;
                }
                // Es un cliente nuevo. Utilizamos los datos del cliente.
                $items[$cliente->codcliente] = [
                    'cifnif' => '',
                    'cliente' => $cliente->codcliente,
                    'codpostal' => '',
                    'ciudad' => '',
                    'provincia' => '',
                    't1' => 0.0,
                    't2' => 0.0,
                    't3' => 0.0,
                    't4' => 0.0,
                    'total' => 0.0
                ];

                $dir = $cliente->getDefaultAddress();
                $items[$cliente->codcliente]['cifnif'] = $cliente->cifnif;
                $items[$cliente->codcliente]['cliente'] = $cliente->razonsocial;
                $items[$cliente->codcliente]['codpais'] = $dir->codpais;
                $items[$cliente->codcliente]['codpostal'] = $dir->codpostal;
                $items[$cliente->codcliente]['ciudad'] = $dir->ciudad;
                $items[$cliente->codcliente]['provincia'] = $dir->provincia;
                $items[$cliente->codcliente]['tipoidfiscal'] = $cliente->tipoidfiscal;

                $this->groupTotals($items[$cliente->codcliente], $row);
            }
        }

        return $items;
    }

    /**
     * Obtiene los importes de los clientes a partir de las facturas de venta.
     *
     * @return array
     */
    protected function getCustomersDataInvoices(): array
    {
        $fiscalNumbers = [];
        $items = [];
        $sql = $this->getInvoiceSql('facturascli', 'codcliente');
        foreach ($this->dataBase->select($sql) as $row) {
            $codcliente = $this->getCodeForTotal($fiscalNumbers, $row['codcliente'], $row['cifnif']);
            if (isset($items[$codcliente])) {
                $this->groupTotals($items[$codcliente], $row);
                continue;
            }

            $items[$codcliente] = [
                'cifnif' => '',
                'cliente' => $row['codcliente'],
                'codpostal' => '',
                'ciudad' => '',
                'provincia' => '',
                't1' => 0.0,
                't2' => 0.0,
                't3' => 0.0,
                't4' => 0.0,
                'total' => 0.0
            ];

            $cliente = new Cliente();
            if ($cliente->load($codcliente)) {
                $dir = $cliente->getDefaultAddress();
                $items[$codcliente]['cifnif'] = $cliente->cifnif;
                $items[$codcliente]['cliente'] = $cliente->razonsocial;
                $items[$codcliente]['codpais'] = $dir->codpais;
                $items[$codcliente]['codpostal'] = $dir->codpostal;
                $items[$codcliente]['ciudad'] = $dir->ciudad;
                $items[$codcliente]['provincia'] = $dir->provincia;
                $items[$codcliente]['tipoidfiscal'] = $cliente->tipoidfiscal;
            }

            $this->groupTotals($items[$codcliente], $row);
        }

        return $items;
    }

    /**
     * Devuelve el SQL para obtener los datos para el cálculo de los totales.
     *
     * @param string $tableName tabla de facturas (facturascli o facturasprov).
     * @param string $codeField campo del tercero (codcliente o codproveedor).
     *
     * @return string
     */
    protected function getInvoiceSql(string $tableName, string $codeField): string
    {
        $sql = 'SELECT ' . $codeField . ', cifnif, EXTRACT(MONTH FROM fecha) as mes, sum(total + COALESCE(totalirpf, 0) - COALESCE(totalsuplidos, 0)) as total'
            . ' FROM ' . $tableName
            . ' INNER JOIN series ON ' . $tableName . '.codserie = series.codserie AND COALESCE(series.siniva, false) = false'
            . $this->getInvoiceSqlWhere($tableName);

        if ($this->excludeIrpf) {
            $sql .= ' AND ' . $tableName . '.irpf = 0';
        }

        $sql .= ' GROUP BY 1, 2, 3 ORDER BY 1;';
        return $sql;
    }

    /**
     * Devuelve la cláusula WHERE para filtrar los documentos: facturas del ejercicio
     * no excluidas del 347. El ejercicio ya identifica a la empresa.
     *
     * @param string $tableName tabla de facturas.
     *
     * @return string
     */
    protected function getInvoiceSqlWhere(string $tableName): string
    {
        return ' WHERE ' . $tableName . '.codejercicio = ' . $this->dataBase->var2str($this->codejercicio)
            . ' AND COALESCE(excluir347, false) = false';
    }

    /**
     * Obtiene los importes de los proveedores y acreedores a partir de la contabilidad
     * (haber de sus subcuentas).
     *
     * @return array
     */
    protected function getSuppliersDataAccounting(): array
    {
        $items = [];
        $fiscalNumbers = [];

        // buscamos las cuentas especiales de proveedores de este ejercicio
        $cuentaModel = new Cuenta();
        $where = [
            Where::eq('codejercicio', $this->codejercicio),
            Where::in('codcuentaesp', ['PROVEE', 'ACREED']),
        ];
        foreach ($cuentaModel->all($where, [], 0, 0) as $cuenta) {
            // consultamos las partidas de cada subcuenta hija
            foreach ($this->getAccountingInfo($cuenta, 'haber') as $row) {
                // buscamos el proveedor de la subcuenta
                $proveedor = new Proveedor();
                $where = [Where::eq('codsubcuenta', $row['codsubcuenta'])];
                if (false === $proveedor->loadWhere($where)) {
                    // no existe, saltamos
                    continue;
                }

                $codproveedor = $this->getCodeForTotal($fiscalNumbers, $proveedor->codproveedor, $proveedor->cifnif);
                if (isset($items[$codproveedor])) {
                    $this->groupTotals($items[$codproveedor], $row);
                    continue;
                }

                // Es un proveedor nuevo. Utilizamos los datos del proveedor.
                $items[$proveedor->codproveedor] = [
                    'cifnif' => '',
                    'proveedor' => $proveedor->codproveedor,
                    'codpostal' => '',
                    'ciudad' => '',
                    'provincia' => '',
                    't1' => 0.0,
                    't2' => 0.0,
                    't3' => 0.0,
                    't4' => 0.0,
                    'total' => 0.0
                ];

                $dir = $proveedor->getDefaultAddress();
                $items[$proveedor->codproveedor]['cifnif'] = $proveedor->cifnif;
                $items[$proveedor->codproveedor]['proveedor'] = $proveedor->razonsocial;
                $items[$proveedor->codproveedor]['codpais'] = $dir->codpais;
                $items[$proveedor->codproveedor]['codpostal'] = $dir->codpostal;
                $items[$proveedor->codproveedor]['ciudad'] = $dir->ciudad;
                $items[$proveedor->codproveedor]['provincia'] = $dir->provincia;
                $items[$proveedor->codproveedor]['tipoidfiscal'] = $proveedor->tipoidfiscal;

                $this->groupTotals($items[$proveedor->codproveedor], $row);
            }
        }

        return $items;
    }

    /**
     * Obtiene los importes de los proveedores a partir de las facturas de compra.
     *
     * @return array
     */
    protected function getSuppliersDataInvoices(): array
    {
        $fiscalNumbers = [];
        $items = [];
        $sql = $this->getInvoiceSql('facturasprov', 'codproveedor');
        foreach ($this->dataBase->select($sql) as $row) {
            $codproveedor = $this->getCodeForTotal($fiscalNumbers, $row['codproveedor'], $row['cifnif']);
            if (isset($items[$codproveedor])) {
                $this->groupTotals($items[$codproveedor], $row);
                continue;
            }

            $items[$codproveedor] = [
                'cifnif' => '',
                'proveedor' => $row['codproveedor'],
                'codpostal' => '',
                'ciudad' => '',
                'provincia' => '',
                't1' => 0.0,
                't2' => 0.0,
                't3' => 0.0,
                't4' => 0.0,
                'total' => 0.0
            ];

            $proveedor = new Proveedor();
            if ($proveedor->load($codproveedor)) {
                $dir = $proveedor->getDefaultAddress();
                $items[$codproveedor]['cifnif'] = $proveedor->cifnif;
                $items[$codproveedor]['proveedor'] = $proveedor->razonsocial;
                $items[$codproveedor]['codpais'] = $dir->codpais;
                $items[$codproveedor]['codpostal'] = $dir->codpostal;
                $items[$codproveedor]['ciudad'] = $dir->ciudad;
                $items[$codproveedor]['provincia'] = $dir->provincia;
                $items[$codproveedor]['tipoidfiscal'] = $proveedor->tipoidfiscal;
            }

            $this->groupTotals($items[$codproveedor], $row);
        }

        return $items;
    }

    /**
     * Suma el importe de una fila mensual en el trimestre que le corresponde y en el total.
     *
     * @param array $item fila del tercero, se actualiza por referencia.
     * @param array $row  fila con el mes ('mes') y el importe ('total').
     */
    protected function groupTotals(array &$item, array $row): void
    {
        if (in_array($row['mes'], ['1', '2', '3', '01', '02', '03'])) {
            $item['t1'] += (float)$row['total'];
        } elseif (in_array($row['mes'], ['4', '5', '6', '04', '05', '06'])) {
            $item['t2'] += (float)$row['total'];
        } elseif (in_array($row['mes'], ['7', '8', '9', '07', '08', '09'])) {
            $item['t3'] += (float)$row['total'];
        } else {
            $item['t4'] += (float)$row['total'];
        }

        $item['total'] += (float)$row['total'];
    }
}
