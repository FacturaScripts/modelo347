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

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Dinamic\Lib\Txt347Export;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class Txt347ExportTest extends TestCase
{
    use LogErrorsTrait;
    use DefaultSettingsTrait;

    public static function setUpBeforeClass(): void
    {
        self::setDefaultSettings();
    }

    // -------------------------------------------------------------------------
    // formatAmount
    // -------------------------------------------------------------------------

    public function testFormatAmountPositive(): void
    {
        $result = Txt347Export::formatAmount(1234.56, 16, STR_PAD_LEFT);
        $this->assertEquals(16, strlen($result));
        $this->assertEquals(' ', $result[0]);
        $this->assertStringEndsWith('123456', $result);
    }

    public function testFormatAmountNegative(): void
    {
        $result = Txt347Export::formatAmount(-1234.56, 16, STR_PAD_LEFT);
        $this->assertEquals(16, strlen($result));
        $this->assertEquals('N', $result[0]);
        $this->assertStringEndsWith('123456', $result);
    }

    public function testFormatAmountZero(): void
    {
        $result = Txt347Export::formatAmount(0.0, 16, STR_PAD_LEFT);
        $this->assertEquals(16, strlen($result));
        $this->assertEquals(' 000000000000000', $result);
    }

    public function testFormatAmountRoundsToCents(): void
    {
        // 1000.01 → 100001 centavos
        $result = Txt347Export::formatAmount(1000.01, 16, STR_PAD_LEFT);
        $this->assertStringEndsWith('100001', $result);
    }

    public function testFormatAmountAlwaysCorrectLength(): void
    {
        foreach ([0.0, 1.0, -1.5, 99999.99, -99999.99] as $amount) {
            $result = Txt347Export::formatAmount($amount, 16, STR_PAD_LEFT);
            $this->assertEquals(16, strlen($result), "formatAmount($amount) debe retornar 16 caracteres");
        }
    }

    // -------------------------------------------------------------------------
    // formatString
    // -------------------------------------------------------------------------

    public function testFormatStringPadsRight(): void
    {
        $result = Txt347Export::formatString('hola', 10, ' ', STR_PAD_RIGHT);
        $this->assertEquals('HOLA      ', $result);
        $this->assertEquals(10, strlen($result));
    }

    public function testFormatStringPadsLeft(): void
    {
        $result = Txt347Export::formatString('42', 9, '0', STR_PAD_LEFT);
        $this->assertEquals('000000042', $result);
    }

    public function testFormatStringTruncates(): void
    {
        $result = Txt347Export::formatString('abcdefghij', 5, ' ', STR_PAD_RIGHT);
        $this->assertEquals('ABCDE', $result);
        $this->assertEquals(5, strlen($result));
    }

    public function testFormatStringEmpty(): void
    {
        $result = Txt347Export::formatString('', 9, ' ', STR_PAD_RIGHT);
        $this->assertEquals('         ', $result);
        $this->assertEquals(9, strlen($result));
    }

    public function testFormatStringRemovesAccents(): void
    {
        $result = Txt347Export::formatString('café résumé', 15, ' ', STR_PAD_RIGHT);
        $this->assertStringStartsWith('CAFE RESUME', $result);
    }

    public function testFormatStringPreservesEnye(): void
    {
        $result = Txt347Export::formatString('España', 10, ' ', STR_PAD_RIGHT);
        $this->assertStringContainsString('Ñ', $result);
    }

    // -------------------------------------------------------------------------
    // formatOnlyNumber
    // -------------------------------------------------------------------------

    public function testFormatOnlyNumberStripsNonDigits(): void
    {
        $this->assertEquals('915551234', Txt347Export::formatOnlyNumber('91 555 12 34'));
        $this->assertEquals('123', Txt347Export::formatOnlyNumber('abc-123'));
        $this->assertEquals('', Txt347Export::formatOnlyNumber(''));
        $this->assertEquals('', Txt347Export::formatOnlyNumber('abc'));
    }

    // -------------------------------------------------------------------------
    // getProvincia
    // -------------------------------------------------------------------------

    public function testGetProvinciaKnownProvinces(): void
    {
        $this->assertEquals('28', Txt347Export::getProvincia('Madrid'));
        $this->assertEquals('08', Txt347Export::getProvincia('Barcelona'));
        $this->assertEquals('46', Txt347Export::getProvincia('Valencia'));
        $this->assertEquals('41', Txt347Export::getProvincia('Sevilla'));
        $this->assertEquals('15', Txt347Export::getProvincia('A Coruña'));
        $this->assertEquals('51', Txt347Export::getProvincia('Ceuta'));
        $this->assertEquals('52', Txt347Export::getProvincia('Melilla'));
    }

    public function testGetProvinciaWithAccents(): void
    {
        $this->assertEquals('29', Txt347Export::getProvincia('Málaga'));
        $this->assertEquals('04', Txt347Export::getProvincia('Almería'));
        $this->assertEquals('14', Txt347Export::getProvincia('Córdoba'));
        $this->assertEquals('01', Txt347Export::getProvincia('Álava'));
    }

    public function testGetProvinciaCaseInsensitive(): void
    {
        $this->assertEquals('28', Txt347Export::getProvincia('MADRID'));
        $this->assertEquals('08', Txt347Export::getProvincia('barcelona'));
        $this->assertEquals('41', Txt347Export::getProvincia('SEVILLA'));
    }

    public function testGetProvinciaUnknownReturns99(): void
    {
        $this->assertEquals('99', Txt347Export::getProvincia('Unknown'));
        $this->assertEquals('99', Txt347Export::getProvincia(null));
        $this->assertEquals('99', Txt347Export::getProvincia(''));
    }

    public function testGetProvinciaAlwaysReturnsTwoChars(): void
    {
        $provinces = ['Madrid', 'Barcelona', 'Valencia', null, '', 'Unknown', 'Sevilla', 'Tenerife'];
        foreach ($provinces as $provincia) {
            $result = Txt347Export::getProvincia($provincia);
            $this->assertEquals(2, strlen($result), "getProvincia('$provincia') debe retornar 2 caracteres");
        }
    }

    // -------------------------------------------------------------------------
    // sanitize
    // -------------------------------------------------------------------------

    public function testSanitizeRemovesAccents(): void
    {
        $this->assertEquals('cafe', Txt347Export::sanitize('café'));
        $this->assertEquals('aeiou', Txt347Export::sanitize('áéíóú'));
        $this->assertEquals('AEIOU', Txt347Export::sanitize('ÁÉÍÓÚ'));
    }

    public function testSanitizePreservesEnye(): void
    {
        $result = Txt347Export::sanitize('España');
        $this->assertStringContainsString('ñ', $result);

        $result2 = Txt347Export::sanitize('SEÑOR');
        $this->assertStringContainsString('Ñ', $result2);
    }

    public function testSanitizeNull(): void
    {
        $this->assertEquals('', Txt347Export::sanitize(null));
    }

    public function testSanitizeEmpty(): void
    {
        $this->assertEquals('', Txt347Export::sanitize(''));
    }

    // -------------------------------------------------------------------------
    // export() — tests de integración
    // -------------------------------------------------------------------------

    public function testNifOperadorComunitario(): void
    {
        // el código de país son las dos primeras letras del NIF-IVA: EL para Grecia, no su código ISO (GR)
        foreach (['EL123456789', 'GR123456789', '123456789', 'el-123 456 789'] as $cifnif) {
            $this->assertSame('EL123456789      ', $this->nifOperadorComunitario('GRC', $cifnif), "NIF del operador griego incorrecto para {$cifnif}");
        }

        // el resto de países de la UE, con su código ISO, también si el NIF viene en minúsculas
        $this->assertSame('DE123456789      ', $this->nifOperadorComunitario('DEU', 'de123456789'));

        // ni los de España ni los de fuera de la UE (el Reino Unido salió el 31/01/2020) son operadores comunitarios
        $this->assertSame(str_repeat(' ', 17), $this->nifOperadorComunitario('ESP', 'B12345678'));
        $this->assertSame(str_repeat(' ', 17), $this->nifOperadorComunitario('GBR', 'GB123456789'));
        $this->assertSame(str_repeat(' ', 17), $this->nifOperadorComunitario('USA', '123456789'));
    }

    private function nifOperadorComunitario(string $codpais, string $cifnif): string
    {
        $method = new ReflectionMethod(Txt347Export::class, 'getNifOperadorComunitario');
        $method->setAccessible(true);
        return $method->invoke(null, ['codpais' => $codpais, 'cifnif' => $cifnif]);
    }

    private function getFirstExercise(): ?Ejercicio
    {
        $ejercicio = new Ejercicio();
        $exercises = $ejercicio->all();
        return empty($exercises) ? null : $exercises[0];
    }

    private function sampleCustomer(): array
    {
        return [
            'codpais' => 'ESP',
            'cifnif' => 'B12345678',
            'cliente' => 'Empresa Cliente SL',
            'provincia' => 'Madrid',
            'total' => 1000.00,
            't1' => 250.00,
            't2' => 250.00,
            't3' => 250.00,
            't4' => 250.00,
        ];
    }

    private function sampleSupplier(): array
    {
        return [
            'codpais' => 'ESP',
            'cifnif' => 'A87654321',
            'proveedor' => 'Proveedor SA',
            'provincia' => 'Barcelona',
            'total' => 2000.00,
            't1' => 500.00,
            't2' => 500.00,
            't3' => 500.00,
            't4' => 500.00,
        ];
    }

    public function testExportRecordLength(): void
    {
        $exercise = $this->getFirstExercise();
        if ($exercise === null) {
            $this->markTestSkipped('No hay ejercicios disponibles');
        }

        $result = Txt347Export::export(
            $exercise->codejercicio,
            [$this->sampleCustomer()],
            [$this->sampleSupplier()]
        );

        $lines = explode("\n", $result);
        $this->assertCount(3, $lines, 'Debe haber 3 líneas: tipo 1 + 2 tipo 2');
        $this->assertEquals(500, strlen($lines[0]), 'El registro tipo 1 debe tener 500 caracteres');
        $this->assertEquals(500, strlen($lines[1]), 'El registro tipo 2 (cliente) debe tener 500 caracteres');
        $this->assertEquals(500, strlen($lines[2]), 'El registro tipo 2 (proveedor) debe tener 500 caracteres');
    }

    public function testExportRecordTypes(): void
    {
        $exercise = $this->getFirstExercise();
        if ($exercise === null) {
            $this->markTestSkipped('No hay ejercicios disponibles');
        }

        $result = Txt347Export::export(
            $exercise->codejercicio,
            [$this->sampleCustomer()],
            [$this->sampleSupplier()]
        );

        $lines = explode("\n", $result);
        $this->assertEquals('1', $lines[0][0], 'El primer registro debe ser tipo 1');
        $this->assertEquals('2', $lines[1][0], 'El segundo registro debe ser tipo 2');
        $this->assertEquals('2', $lines[2][0], 'El tercer registro debe ser tipo 2');
        $this->assertEquals('347', substr($lines[0], 1, 3), 'El modelo debe ser 347');
        $this->assertEquals('347', substr($lines[1], 1, 3));
        $this->assertEquals('347', substr($lines[2], 1, 3));
    }

    public function testExportTipoHoja(): void
    {
        $exercise = $this->getFirstExercise();
        if ($exercise === null) {
            $this->markTestSkipped('No hay ejercicios disponibles');
        }

        $result = Txt347Export::export($exercise->codejercicio, [$this->sampleCustomer()], []);
        $lines = explode("\n", $result);

        // Posición tipo hoja: 1+3+4+9+9+9+40 = 75 (índice base 0)
        $this->assertEquals('D', $lines[1][75], 'El tipo de hoja debe ser D');
    }

    public function testExportClaveOperacion(): void
    {
        $exercise = $this->getFirstExercise();
        if ($exercise === null) {
            $this->markTestSkipped('No hay ejercicios disponibles');
        }

        $result = Txt347Export::export(
            $exercise->codejercicio,
            [$this->sampleCustomer()],
            [$this->sampleSupplier()]
        );

        $lines = explode("\n", $result);
        // Posición clave operación: 1+3+4+9+9+9+40+1+2+2+1 = 81 (índice base 0)
        $this->assertEquals('B', $lines[1][81], 'La clave de operación del cliente debe ser B');
        $this->assertEquals('A', $lines[2][81], 'La clave de operación del proveedor debe ser A');
    }

    public function testExportTotalReset(): void
    {
        $exercise = $this->getFirstExercise();
        if ($exercise === null) {
            $this->markTestSkipped('No hay ejercicios disponibles');
        }

        // Primera exportación con 1000€
        $result1 = Txt347Export::export($exercise->codejercicio, [$this->sampleCustomer()], []);
        $lines1 = explode("\n", $result1);

        // Segunda exportación con los mismos datos: el total NO debe duplicarse
        $result2 = Txt347Export::export($exercise->codejercicio, [$this->sampleCustomer()], []);
        $lines2 = explode("\n", $result2);

        // El registro tipo 1 (empresa) debe ser idéntico en ambas exportaciones
        $this->assertEquals($lines1[0], $lines2[0], 'El total no debe acumularse entre llamadas a export()');
    }

    public function testExportEmptyData(): void
    {
        $exercise = $this->getFirstExercise();
        if ($exercise === null) {
            $this->markTestSkipped('No hay ejercicios disponibles');
        }

        $result = Txt347Export::export($exercise->codejercicio, [], []);
        $lines = explode("\n", $result);
        $this->assertCount(1, $lines, 'Con datos vacíos solo debe haber un registro tipo 1');
        $this->assertEquals(500, strlen($lines[0]));
    }

    // -------------------------------------------------------------------------
    // export() — codificación ISO-8859-1
    // -------------------------------------------------------------------------

    public function testExportEncodingIsISO88591(): void
    {
        $exercise = $this->getFirstExercise();
        if ($exercise === null) {
            $this->markTestSkipped('No hay ejercicios disponibles');
        }

        $customer = $this->sampleCustomer();
        $customer['cliente'] = 'Empresa Española SL';

        $result = Txt347Export::export($exercise->codejercicio, [$customer], []);

        // El contenido no debe ser UTF-8 válido (la Ñ en ISO-8859-1 rompe la secuencia UTF-8)
        $this->assertFalse(
            mb_check_encoding($result, 'UTF-8'),
            'El contenido exportado no debe estar en UTF-8'
        );

        // La Ñ debe estar codificada como el byte 0xD1 (ISO-8859-1)
        $this->assertStringContainsString("\xD1", $result, 'La Ñ debe estar codificada como byte 0xD1 (ISO-8859-1)');
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
