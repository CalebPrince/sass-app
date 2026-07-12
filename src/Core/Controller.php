<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Base controller. Holds the shared config and a couple of tiny validation
 * helpers so feature controllers stay focused on behavior.
 */
abstract class Controller
{
    public function __construct(protected array $config) {}

    protected function tier(string $key): ?array
    {
        return $this->config['tiers'][$key] ?? null;
    }

    protected function require(Request $request, array $fields): array
    {
        $missing = [];
        $values = [];
        foreach ($fields as $field) {
            $val = $request->input($field);
            if ($val === null || $val === '') {
                $missing[] = $field;
            }
            $values[$field] = is_string($val) ? trim($val) : $val;
        }
        if ($missing) {
            Response::error('Missing required fields', 422, ['missing' => $missing]);
        }
        return $values;
    }

    protected function validPassword(string $password): bool
    {
        $min = $this->config['security']['password_min_len'];
        // At least min length, containing a letter and a number.
        return strlen($password) >= $min
            && preg_match('/[A-Za-z]/', $password)
            && preg_match('/[0-9]/', $password);
    }
}
