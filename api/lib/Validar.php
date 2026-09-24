<?php
declare(strict_types=1);
if (!defined('CAMCA_BOOT')) { http_response_code(404); exit; }

/**
 * Validadores puros. Acumulan errores por campo y al final se lanzan
 * juntos, para que el cliente pueda marcar todos los campos de una vez
 * en vez de descubrirlos de a uno.
 */
final class Validar
{
    private array $errores = [];
    public function __construct(private readonly array $datos) {}

    private function valor(string $campo): mixed { return $this->datos[$campo] ?? null; }

    public function texto(string $campo, int $min = 1, int $max = 255, bool $requerido = true): ?string
    {
        $v = $this->valor($campo);
        if ($v === null || $v === '') {
            if ($requerido) $this->errores[$campo] = 'Es obligatorio.';
            return null;
        }
        if (!is_string($v)) { $this->errores[$campo] = 'Tiene que ser texto.'; return null; }
        $v = trim($v);
        $largo = mb_strlen($v);
        if ($largo < $min)  { $this->errores[$campo] = "Mínimo $min caracteres."; return null; }
        if ($largo > $max)  { $this->errores[$campo] = "Máximo $max caracteres."; return null; }
        return $v;
    }

    public function email(string $campo, bool $requerido = true): ?string
    {
        $v = $this->texto($campo, 5, 190, $requerido);
        if ($v === null) return null;
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $this->errores[$campo] = 'No parece un email válido.';
            return null;
        }
        return mb_strtolower($v);
    }

    /** Teléfono argentino, tolerante con espacios, guiones y paréntesis. */
    public function telefono(string $campo, bool $requerido = false): ?string
    {
        $v = $this->texto($campo, 6, 30, $requerido);
        if ($v === null) return null;
        $limpio = preg_replace('/[^\d+]/', '', $v) ?? '';
        if (mb_strlen($limpio) < 8) { $this->errores[$campo] = 'Parece incompleto.'; return null; }
        return $limpio;
    }

    public function entero(string $campo, int $min, int $max, bool $requerido = true): ?int
    {
        $v = $this->valor($campo);
        if ($v === null || $v === '') {
            if ($requerido) $this->errores[$campo] = 'Es obligatorio.';
            return null;
        }
        if (!is_numeric($v)) { $this->errores[$campo] = 'Tiene que ser un número.'; return null; }
        $n = (int) $v;
        if ($n < $min || $n > $max) { $this->errores[$campo] = "Tiene que estar entre $min y $max."; return null; }
        return $n;
    }

    public function enum(string $campo, array $permitidos, bool $requerido = true): ?string
    {
        $v = $this->texto($campo, 1, 60, $requerido);
        if ($v === null) return null;
        if (!in_array($v, $permitidos, true)) {
            $this->errores[$campo] = 'Valor no permitido.';
            return null;
        }
        return $v;
    }

    public function uuid(string $campo, bool $requerido = true): ?string
    {
        $v = $this->texto($campo, 36, 36, $requerido);
        if ($v === null) return null;
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-9][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $v)) {
            $this->errores[$campo] = 'No es un UUID válido.';
            return null;
        }
        return mb_strtolower($v);
    }

    public function coordenada(string $campo, float $min, float $max, bool $requerido = true): ?float
    {
        $v = $this->valor($campo);
        if ($v === null || $v === '') {
            if ($requerido) $this->errores[$campo] = 'Es obligatorio.';
            return null;
        }
        if (!is_numeric($v)) { $this->errores[$campo] = 'Tiene que ser un número.'; return null; }
        $f = (float) $v;
        if ($f < $min || $f > $max) { $this->errores[$campo] = 'Fuera de rango.'; return null; }
        return $f;
    }

    public function hay(): bool { return $this->errores !== []; }

    /** Lanza si hubo algún error. Se llama una sola vez, al final. */
    public function fin(): void
    {
        if ($this->errores) throw new ErrorValidacion($this->errores);
    }
}
