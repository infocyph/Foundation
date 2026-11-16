<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwire;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Validation\FormRequest;
use Infocyph\Foundation\Validation\ValidatorFactory;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Webrick\Request\Request;

it('rejects unsafe uploaded file paths rather than inspecting forged MIME sources', function (): void {
    $factory = Foundation::web()->boot()->make(ValidatorFactory::class);
    $validator = $factory->makeRules(['document' => 'mimetypes:text/plain']);
    $file = tempnam(sys_get_temp_dir(), 'foundation-reqshield-');
    expect($file)->toBeString();

    try {
        file_put_contents($file, "verified plain text\n");
        $upload = static fn(string $path): array => [
            'tmp_name' => $path,
            'name' => 'document.txt',
            'type' => 'text/plain',
            'size' => 20,
            'error' => UPLOAD_ERR_OK,
        ];

        expect($validator->validate(['document' => $upload($file)])->passes())->toBeTrue();

        foreach ([
            '',
            'data://text/plain,forged-content',
            'php://filter/read=convert.base64-encode/resource=' . $file,
            'phar://' . $file,
            $file . "\0forged",
        ] as $unsafe) {
            expect($validator->validate(['document' => $upload($unsafe)])->fails())
                ->toBeTrue();
        }
    } finally {
        if (is_file($file)) {
            unlink($file);
        }
    }
});

it('preserves host cancellation while validating a Foundation FormRequest', function (): void {
    $factory = Foundation::web()->boot()->make(ValidatorFactory::class);
    $form = new class($factory) extends FormRequest {
        #[\Override]
        protected function rules(Request $request): array
        {
            unset($request);

            return ['code' => 'required|string'];
        }
    };
    $runtime = RuntimeContext::standalone();
    $hostRequest = RequestContext::create($runtime);
    CacheRunwire::bind($runtime);

    try {
        CacheRunwire::share($hostRequest, null, static function () use ($form, $hostRequest): void {
            $hostRequest->cancel(CancellationReason::HOST_CANCELLED);
            expect(fn() => $form->validate(Request::fake(
                post: ['code' => 'valid'],
                method: 'POST',
                headers: ['Host' => 'localhost'],
            )))->toThrow(CancelledException::class);
        }, $runtime);
    } finally {
        $hostRequest->complete();
        CacheRunwire::release($runtime);
    }
});
