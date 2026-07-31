<?php

/**
 * Normaliza y valida las identidades de contacto usadas por reservaciones.
 *
 * El valor normalizado es la única autoridad para comparar una sesión pública
 * con sus reservaciones. Correo y teléfono permanecen como identidades
 * independientes; esta etapa no intenta deducir que pertenecen a una persona.
 */

namespace Services;

use InvalidArgumentException;

class ContactoService
{
    public const TIPO_EMAIL = 'email';
    public const TIPO_TELEFONO = 'telefono';
    public const TIPOS = [self::TIPO_EMAIL, self::TIPO_TELEFONO];

    /**
     * @throws InvalidArgumentException Cuando el tipo o el formato no son válidos.
     */
    public static function normalizar(string $tipo, string $contacto): string
    {
        return match ($tipo) {
            self::TIPO_EMAIL => self::normalizarEmail($contacto),
            self::TIPO_TELEFONO => self::normalizarTelefono($contacto),
            default => throw new InvalidArgumentException('Tipo de contacto no válido.'),
        };
    }

    /**
     * El correo se compara sin espacios externos y en minúsculas.
     *
     * @throws InvalidArgumentException Cuando el correo no tiene un formato válido.
     */
    public static function normalizarEmail(string $email): string
    {
        $email = trim($email);
        $email = function_exists('mb_strtolower')
            ? mb_strtolower($email, 'UTF-8')
            : strtolower($email);

        if (
            $email === ''
            || strlen($email) > ReservacionConfig::EMAIL_MAX_CARACTERES
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new InvalidArgumentException('Escribe un correo electrónico válido.');
        }

        return $email;
    }

    /**
     * Normaliza teléfonos mexicanos al formato E.164 +52 seguido de 10 dígitos.
     *
     * Solo se eliminan caracteres de presentación conocidos. Se exige el prefijo
     * +52 en la interfaz para no asumir silenciosamente un país ante valores
     * ambiguos.
     *
     * @throws InvalidArgumentException Cuando no se recibe +52 y diez dígitos.
     */
    public static function normalizarTelefono(string $telefono): string
    {
        $telefono = trim($telefono);
        $normalizado = preg_replace('/[\s\-\(\)\.]+/u', '', $telefono);

        if (!is_string($normalizado) || preg_match('/^\+52\d{10}$/', $normalizado) !== 1) {
            throw new InvalidArgumentException(
                'Usa el formato internacional de México: +52 seguido de 10 dígitos.'
            );
        }

        return $normalizado;
    }

    /**
     * Oculta parte del contacto para mensajes de interfaz no esenciales.
     */
    public static function enmascarar(string $tipo, string $contactoNormalizado): string
    {
        if ($tipo === self::TIPO_EMAIL) {
            [$usuario, $dominio] = array_pad(explode('@', $contactoNormalizado, 2), 2, '');
            $visible = substr($usuario, 0, min(2, strlen($usuario)));

            return $visible . str_repeat('•', max(2, strlen($usuario) - strlen($visible))) . '@' . $dominio;
        }

        return '+52 ••• ••• ' . substr($contactoNormalizado, -4);
    }
}
