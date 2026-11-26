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
use FacturaScripts\Dinamic\Lib\Email\NewMail;
use FacturaScripts\Dinamic\Lib\Export\XLSExport;
use FacturaScripts\Dinamic\Lib\Modelo347 as Modelo347Lib;
use FacturaScripts\Dinamic\Lib\Modelo347Email;
use FacturaScripts\Dinamic\Lib\Modelo347File;
use FacturaScripts\Dinamic\Model\Ejercicio;

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
        $this->execAction($action);
    }

    /**
     * Calcula los clientes y proveedores a declarar con los filtros cargados y guarda sus datos y totales.
     * El cálculo está en Lib/Modelo347, compartido con el endpoint de la API.
     *
     * @return Modelo347Lib cálculo ya ejecutado, para consultar sus avisos.
     */
    protected function calculate(): Modelo347Lib
    {
        $model347 = new Modelo347Lib($this->codejercicio, $this->amount, (string)$this->examine, (string)$this->grouping, $this->excludeIrpf);
        $model347->calculate();
        $this->customersData = $model347->customersData;
        $this->customersTotals = $model347->customersTotals;
        $this->suppliersData = $model347->suppliersData;
        $this->suppliersTotals = $model347->suppliersTotals;
        return $model347;
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
        $this->loadFilters();
        $model347 = $this->calculate();
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

    /**
     * Ejecuta la acción solicitada tras cargar los datos.
     *
     * @param string $action
     */
    protected function execAction(string $action): void
    {
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
     * Lee de la petición los filtros de cálculo y de la declaración.
     * Si no se indica ejercicio, usa el primer ejercicio abierto.
     */
    protected function loadFilters(): void
    {
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
    }

    /**
     * Encola los emails del Modelo 347 a los clientes, proveedores o ambos y muestra al usuario cuántos
     * se han encolado, quiénes no tienen email y si había un envío anterior pendiente. Requiere permiso
     * de modificación y que el email esté configurado.
     */
    protected function sendEmailsAction(): void
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-modify');
            return;
        }

        if (false === NewMail::create()->canSendMail()) {
            Tools::log()->warning('email-not-configured');
            return;
        }

        $exercise = new Ejercicio();
        if (false === $exercise->load($this->codejercicio)) {
            Tools::log()->warning('exercise-not-found');
            return;
        }

        $sendtype = $this->request->request->get('sendtype', 'both');
        $types = [];
        if (in_array($sendtype, ['customers', 'both'])) {
            $types[Modelo347Email::TYPE_CUSTOMERS] = $this->customersData;
        }
        if (in_array($sendtype, ['suppliers', 'both'])) {
            $types[Modelo347Email::TYPE_SUPPLIERS] = $this->suppliersData;
        }

        $queued = 0;
        foreach ($types as $type => $data) {
            if (empty($data)) {
                continue;
            }

            $result = Modelo347Email::queue($exercise, $this->amount, $data, $type);
            if ($result['status'] === 'disabled') {
                // la Lib ya avisa de que la notificación no existe o está desactivada
                continue;
            }
            if ($result['status'] !== 'queued') {
                Tools::log()->warning('347-emails-pending', ['%type%' => Tools::trans($type)]);
                continue;
            }

            $queued += $result['queued'];
            foreach ($result['noEmail'] as $row) {
                Tools::log()->warning('347-no-email', [
                    '%name%' => $row['name'],
                    '%cifnif%' => $row['cifnif'],
                ]);
            }
        }

        if ($queued > 0) {
            Tools::log()->notice('347-emails-queued', ['%count%' => $queued]);
            return;
        }

        Tools::log()->warning('347-emails-none');
    }
}
