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

namespace FacturaScripts\Plugins\Modelo347\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\DataSrc\Ejercicios;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Lib\Export\XLSExport;
use FacturaScripts\Dinamic\Lib\Modelo347 as Modelo347Lib;
use FacturaScripts\Dinamic\Lib\Modelo347File;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\Proveedor;

/**
 * Pantalla del Modelo 347: muestra los clientes y proveedores a declarar, descarga el Excel y el TXT
 * para la AEAT y envía por email a cada tercero sus importes. El cálculo está en Lib/Modelo347,
 * compartido con el endpoint de la API.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class Modelo347 extends Controller
{
    /** @var string */
    public $activetab = 'customers';

    /** @var float */
    public $amount = 3005.06;

    /** @var string */
    public $codejercicio;

    /** @var array */
    public $customersData = [];

    /** @var array */
    public $customersTotals = [];

    /** @var string */
    public $declarationType = '';

    /** @var string */
    public $examine = 'invoices';

    /** @var bool */
    public $excludeIrpf = false;

    /** @var string */
    public $grouping = 'customer-supplier';

    /** @var string */
    public $justificante = '';

    /** @var string */
    public $justificanteAnterior = '';

    /** @var array */
    public $suppliersData = [];

    /** @var array */
    public $suppliersTotals = [];

    /**
     * Devuelve los orígenes de datos disponibles para el selector de la pantalla.
     *
     * @return string[]
     */
    public function allExamine(): array
    {
        return Modelo347Lib::allExamine();
    }

    /**
     * @param int|null $idempresa
     * @return Ejercicio[]
     */
    public function allExercises(?int $idempresa): array
    {
        if (empty($idempresa)) {
            return Ejercicios::all();
        }

        $list = [];
        foreach (Ejercicios::all() as $ejercicio) {
            if ($ejercicio->idempresa === $idempresa) {
                $list[] = $ejercicio;
            }
        }
        return $list;
    }

    /**
     * Devuelve las agrupaciones disponibles para el selector de la pantalla.
     *
     * @return string[]
     */
    public function allGroupBy(): array
    {
        return Modelo347Lib::allGroupBy();
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'reports';
        $data['submenu'] = 'tax-models';
        $data['title'] = 'model-347';
        $data['icon'] = 'fa-solid fa-book';
        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $this->activetab = $this->request->request->get('activetab', $this->activetab);
        $action = $this->request->request->get('action', '');

        $this->defaultAction();
        switch ($action) {
            case 'download-excel':
                $this->downloadExcelAction();
                break;

            case 'download-txt':
                $this->downloadTxtAction();
                break;

            case 'send-emails':
                $this->sendEmailsAction();
                break;
        }
    }

    /**
     * Registra en el log los avisos de los datos calculados: empresa sin administrador o teléfono,
     * declaración sin datos y terceros sin provincia o país.
     *
     * @param Modelo347Lib $model347 cálculo del modelo ya ejecutado.
     */
    protected function checkData(Modelo347Lib $model347): void
    {
        foreach ($model347->checkData() as $warning) {
            Tools::log()->warning($warning['key'], $warning['context']);
        }
    }

    /**
     * Lee los filtros del formulario, calcula los clientes y proveedores a declarar y muestra los avisos.
     */
    protected function defaultAction(): void
    {
        // buscamos el primer ejercicio abierto, para tenerlo como predeterminado
        $codejercicio = null;
        $exerciseModel = new Ejercicio();
        foreach ($exerciseModel->all([], ['fechainicio' => 'DESC'], 0, 0) as $exe) {
            if ($exe->isOpened()) {
                $codejercicio = $exe->codejercicio;
                break;
            }
        }

        $this->amount = (float)$this->request->request->get('amount', $this->amount);
        $this->codejercicio = $this->request->request->get('codejercicio', $codejercicio);
        $this->examine = $this->request->request->get('examine', $this->examine);
        $this->excludeIrpf = (bool)$this->request->request->get('excludeirpf', $this->excludeIrpf);
        $this->grouping = $this->request->request->get('grouping', $this->grouping);
        $this->justificante = preg_replace('/[^0-9]/', '', $this->request->request->get('justificante', ''));
        $this->declarationType = $this->request->request->get('declarationtype', '');
        if (!in_array($this->declarationType, ['C', 'S'])) {
            $this->declarationType = '';
        }
        $this->justificanteAnterior = preg_replace('/[^0-9]/', '', $this->request->request->get('justificanteanterior', ''));

        // el cálculo está en Lib/Modelo347, compartido con el endpoint de la API
        $model347 = new Modelo347Lib($this->codejercicio, $this->amount, (string)$this->examine, (string)$this->grouping, $this->excludeIrpf);
        $model347->calculate();
        $this->customersData = $model347->customersData;
        $this->customersTotals = $model347->customersTotals;
        $this->suppliersData = $model347->suppliersData;
        $this->suppliersTotals = $model347->suppliersTotals;

        $this->checkData($model347);
    }

    protected function downloadExcelAction(): void
    {
        $this->setTemplate(false);

        $xlsExport = new XLSExport();
        $xlsExport->newDoc(Tools::lang()->trans('model-347'), 0, '');
        $i18n = Tools::lang();

        // customers data
        if (false === empty($this->customersData)) {
            $customersHeaders = [
                'cifnif' => $i18n->trans('cifnif'),
                'cliente' => $i18n->trans('customer'),
                'codpostal' => $i18n->trans('zip-code'),
                'ciudad' => $i18n->trans('city'),
                'provincia' => $i18n->trans('province'),
                't1' => $i18n->trans('first-trimester'),
                't2' => $i18n->trans('second-trimester'),
                't3' => $i18n->trans('third-trimester'),
                't4' => $i18n->trans('fourth-trimester'),
                'total' => $i18n->trans('total')
            ];
            $rows1 = $this->customersData;
            $rows1[] = $this->customersTotals;
            $xlsExport->addTablePage($customersHeaders, $rows1);
        }

        // suppliers data
        if (false === empty($this->suppliersData)) {
            $suppliersHeaders = [
                'cifnif' => $i18n->trans('cifnif'),
                'proveedor' => $i18n->trans('supplier'),
                'codpostal' => $i18n->trans('zip-code'),
                'ciudad' => $i18n->trans('city'),
                'provincia' => $i18n->trans('province'),
                't1' => $i18n->trans('first-trimester'),
                't2' => $i18n->trans('second-trimester'),
                't3' => $i18n->trans('third-trimester'),
                't4' => $i18n->trans('fourth-trimester'),
                'total' => $i18n->trans('total')
            ];
            $rows2 = $this->suppliersData;
            $rows2[] = $this->suppliersTotals;
            $xlsExport->addTablePage($suppliersHeaders, $rows2);
        }

        $xlsExport->show($this->response);
    }

    protected function downloadTxtAction(): void
    {
        $this->setTemplate(false);

        // generamos el contenido del archivo
        $fileName = 'modelo_347_' . $this->codejercicio . '.347';
        $content = Modelo347File::generate($this->codejercicio, $this->customersData, $this->suppliersData, $this->justificante, $this->declarationType, $this->justificanteAnterior);

        // devolvemos el archivo directamente
        $this->response
            ->header('Content-Type', 'text/plain; charset=ISO-8859-1')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0')
            ->setContent($content);
    }

    protected function sendEmailsAction(): void
    {
        $sendtype = $this->request->request->get('sendtype', 'both');

        if (in_array($sendtype, ['customers', 'both']) && false === empty($this->customersData)) {
            $data = [];
            $sentEmails = [];
            foreach ($this->customersData as $codcliente => $row) {
                $cliente = new Cliente();
                if (false === $cliente->load($codcliente)) {
                    continue;
                }
                $email = $cliente->email;
                if (empty($email) || isset($sentEmails[$email])) {
                    continue;
                }
                $sentEmails[$email] = true;
                $data[] = [
                    'email' => $email,
                    'name' => $row['cliente'],
                    'cifnif' => $row['cifnif'],
                    't1' => $row['t1'],
                    't2' => $row['t2'],
                    't3' => $row['t3'],
                    't4' => $row['t4'],
                    'total' => $row['total'],
                ];
            }
            if (false === empty($data)) {
                WorkQueue::send('Modelo347.SendCustomersEmails', $this->codejercicio, ['data' => $data, 'amount' => $this->amount]);
            }
        }

        if (in_array($sendtype, ['suppliers', 'both']) && false === empty($this->suppliersData)) {
            $data = [];
            $sentEmails = [];
            foreach ($this->suppliersData as $codproveedor => $row) {
                $proveedor = new Proveedor();
                if (false === $proveedor->load($codproveedor)) {
                    continue;
                }
                $email = $proveedor->email;
                if (empty($email) || isset($sentEmails[$email])) {
                    continue;
                }
                $sentEmails[$email] = true;
                $data[] = [
                    'email' => $email,
                    'name' => $row['proveedor'],
                    'cifnif' => $row['cifnif'],
                    't1' => $row['t1'],
                    't2' => $row['t2'],
                    't3' => $row['t3'],
                    't4' => $row['t4'],
                    'total' => $row['total'],
                ];
            }
            if (false === empty($data)) {
                WorkQueue::send('Modelo347.SendSuppliersEmails', $this->codejercicio, ['data' => $data, 'amount' => $this->amount]);
            }
        }

        Tools::log()->info('347-emails-queued');
    }
}
