<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Validation;

use Infocyph\ReqShield\Support\ValidationResult;
use Infocyph\Webrick\Request\Request;

/**
 * Stateless application request-validation base class.
 *
 * ReqShield owns validation mechanics. Foundation supplies request input,
 * application defaults, and an explicit request-to-validator composition point.
 */
abstract class FormRequest
{
    public function __construct(private readonly ValidatorFactory $validators) {}

    final public function __invoke(Request $request): ValidationResult
    {
        return $this->validate($request);
    }

    final public function validate(Request $request): ValidationResult
    {
        return $this->validators
            ->makeRules($this->rules($request), $this->options($request))
            ->validate($this->input($request));
    }

    /** @return array<int|string,mixed> */
    final public function validated(Request $request): array
    {
        return $this->validate($request)->throw()->typed();
    }

    /** @return array<string,mixed> */
    protected function input(Request $request): array
    {
        return $request->all();
    }

    /**
     * Per-request ReqShield profile overrides. Supported keys match
     * Foundation validation.defaults (messages, aliases, sanitizers, casts,
     * locale, nesting/unknown-field policy, DTO and validation limits).
     *
     * @return array<string,mixed>
     */
    protected function options(Request $request): array
    {
        return [];
    }

    /** @return array<string,mixed> */
    abstract protected function rules(Request $request): array;
}
