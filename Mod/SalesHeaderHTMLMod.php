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

namespace FacturaScripts\Plugins\Modelo347\Mod;

use FacturaScripts\Core\Contract\SalesModInterface;
use FacturaScripts\Core\Model\Base\SalesDocument;
use FacturaScripts\Core\Tools;

/**
 * Add new fields in the modal window of the document header
 *   - excluir347: Allows the user to mark the invoice as excluded from the 347 calculation.
 *
 * @author Jose Antonio Cuello Principal <yopli2000@gmail.com>
 * @author Carlos Garcia Gomez           <carlos@facturascripts.com>
 */
class SalesHeaderHTMLMod implements SalesModInterface
{
    /**
     * Guarda excluir347 del formulario en el documento, si tiene la columna.
     *
     * @param SalesDocument $model documento editado.
     * @param array $formData datos enviados por el formulario.
     */
    public function apply(SalesDocument &$model, array $formData): void
    {
        if ($model->hasColumn('excluir347')) {
            $model->excluir347 = ($formData['excluir347'] ?? '') === 'true';
        }
    }

    /**
     * No hace nada: el plugin solo usa apply().
     *
     * @param SalesDocument $model documento editado.
     * @param array $formData datos enviados por el formulario.
     */
    public function applyBefore(SalesDocument &$model, array $formData): void
    {
    }

    /**
     * No añade assets.
     */
    public function assets(): void
    {
    }

    /**
     * No añade botones a la cabecera.
     *
     * @return array
     */
    public function newBtnFields(): array
    {
        return [];
    }

    /**
     * No añade campos a la cabecera.
     *
     * @return array
     */
    public function newFields(): array
    {
        return [];
    }

    /**
     * Añade el campo excluir347 a la ventana modal de la cabecera.
     *
     * @return array
     */
    public function newModalFields(): array
    {
        return ['excluir347'];
    }

    /**
     * Dibuja el campo excluir347.
     *
     * @param SalesDocument $model documento mostrado.
     * @param string $field nombre del campo.
     *
     * @return string|null null si el campo no es de este mod.
     */
    public function renderField(SalesDocument $model, string $field): ?string
    {
        if ($field == 'excluir347') {
            return $this->excluir347($model);
        }

        return null;
    }

    /**
     * Dibuja el selector Sí/No de excluir347; deshabilitado si el documento no es editable.
     *
     * @param SalesDocument $model documento mostrado.
     *
     * @return string HTML del campo, vacío si el documento no tiene la columna.
     */
    private static function excluir347(SalesDocument $model): string
    {
        if (false === $model->hasColumn('excluir347')) {
            return '';
        }

        $options = [];
        foreach (['false', 'true'] as $row) {
            $txt = ($row === 'true') ? 'yes' : 'no';
            $options[] = ($row == $model->excluir347) ?
                '<option value="' . $row . '" selected>' . Tools::trans($txt) . '</option>' :
                '<option value="' . $row . '">' . Tools::trans($txt) . '</option>';
        }

        $attributes = $model->editable ? 'name="excluir347" required=""' : 'disabled=""';
        return '<div class="col-sm-6">'
            . '<div class="mb-3">' . Tools::trans('exclude-347')
            . '<select ' . $attributes . ' class="form-select">' . implode('', $options) . '</select>'
            . '</div>'
            . '</div>';
    }
}
