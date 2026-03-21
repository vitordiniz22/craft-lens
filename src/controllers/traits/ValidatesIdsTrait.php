<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\controllers\traits;

use yii\web\BadRequestHttpException;

/**
 * Shared ID validation for controller actions.
 */
trait ValidatesIdsTrait
{
    /**
     * Extract and validate a required positive integer body parameter.
     *
     * @throws BadRequestHttpException If the value is less than 1
     */
    protected function requireValidId(string $param, string $label = 'ID'): int
    {
        $id = (int) $this->request->getRequiredBodyParam($param);

        if ($id < 1) {
            throw new BadRequestHttpException("Invalid {$label}");
        }

        return $id;
    }
}
