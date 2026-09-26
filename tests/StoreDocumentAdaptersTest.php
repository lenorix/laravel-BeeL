<?php

use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Lenorix\LaravelBeel\BeelManager;
use Lenorix\LaravelBeel\Exceptions\DocumentAlreadyExists;
use Lenorix\LaravelBeel\Exceptions\DocumentDownloadFailed;
use Lenorix\LaravelBeel\Testing\BeelFake;
use Psr\Http\Message\RequestInterface;

// storePdf() through real Flysystem adapters other than the local one. The S3 adapter runs the real
// AWS SDK with an in-process handler instead of the network, so no request leaves the test.

beforeEach(function () {
    config()->set('services.beel.key', 'beel_sk_test_fake');
    config()->set('services.beel.company_id', 'company-1');
    config()->set('beel.http.retries', 0);
    Sleep::fake();
});

function fakeBeelPdf(string $contents = '%PDF-1.7 adapter test'): void
{
    BeelFake::api()->invoicePdf($contents)->fake();
}

/** A fake S3 bucket: an object store answering the AWS SDK's commands, recording them in order. */
final class FakeS3Bucket
{
    /** @var array<string, array{body: string, type: ?string}> */
    public array $objects = [];

    /** @var list<array{name: string, key: ?string, type: ?string}> */
    public array $commands = [];

    public function client(): S3Client
    {
        return new S3Client([
            'region' => 'eu-west-1',
            'version' => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => fn (CommandInterface $command, RequestInterface $request) => $this->handle($command),
        ]);
    }

    private function handle(CommandInterface $command)
    {
        $key = $command['Key'] ?? null;
        $this->commands[] = ['name' => $command->getName(), 'key' => $key, 'type' => $command['ContentType'] ?? null];

        switch ($command->getName()) {
            case 'PutObject':
                $this->objects[$key] = ['body' => (string) $command['Body'], 'type' => $command['ContentType'] ?? null];

                return Create::promiseFor(new Result([]));
            case 'HeadObject':
                return isset($this->objects[$key])
                    ? Create::promiseFor(new Result(['ContentLength' => strlen($this->objects[$key]['body']), 'ContentType' => $this->objects[$key]['type']]))
                    : Create::rejectionFor(new S3Exception('Not Found', $command, ['code' => 'NotFound', 'response' => new PsrResponse(404)]));
            case 'CopyObject':
                // "/bucket/key", URL-encoded.
                $source = explode('/', ltrim(rawurldecode((string) $command['CopySource']), '/'), 2)[1];
                $this->objects[$key] = $this->objects[$source];

                return Create::promiseFor(new Result([]));
            case 'DeleteObject':
                unset($this->objects[$key]);

                return Create::promiseFor(new Result([]));
            case 'GetObjectAcl':
                return Create::promiseFor(new Result(['Grants' => []]));
            default:
                return Create::promiseFor(new Result([]));
        }
    }
}

function s3Disk(FakeS3Bucket $bucket): void
{
    Storage::extend('fake-s3', function ($app, array $config) use ($bucket) {
        $adapter = new AwsS3V3Adapter($bucket->client(), 'invoices-bucket');

        return new FilesystemAdapter(new Filesystem($adapter), $adapter, $config);
    });
    config()->set('filesystems.disks.s3-test', ['driver' => 'fake-s3']);
}

function memoryDisk(): void
{
    Storage::extend('memory', function ($app, array $config) {
        $adapter = new InMemoryFilesystemAdapter;

        return new FilesystemAdapter(new Filesystem($adapter), $adapter, $config);
    });
    config()->set('filesystems.disks.memory-test', ['driver' => 'memory']);
}

it('stores the PDF on S3 as application/pdf, through a temporary object that it removes', function () {
    $bucket = new FakeS3Bucket;
    s3Disk($bucket);
    fakeBeelPdf();

    app(BeelManager::class)->company()->invoices->storePdf('inv-1', 'invoices/A-42.pdf', disk: 's3-test');

    expect(array_keys($bucket->objects))->toBe(['invoices/A-42.pdf'])
        ->and($bucket->objects['invoices/A-42.pdf'])->toBe(['body' => '%PDF-1.7 adapter test', 'type' => 'application/pdf']);

    $upload = collect($bucket->commands)->firstWhere('name', 'PutObject');
    expect($upload['key'])->toStartWith('invoices/.beel-')->toEndWith('-A-42.pdf')
        ->and($upload['type'])->toBe('application/pdf')
        ->and(collect($bucket->commands)->pluck('name'))->toContain('CopyObject', 'DeleteObject');
});

it('refuses an existing S3 object before calling BeeL, and replaces it with overwrite', function () {
    $bucket = new FakeS3Bucket;
    $bucket->objects['a.pdf'] = ['body' => 'previous', 'type' => 'application/pdf'];
    s3Disk($bucket);
    fakeBeelPdf('%PDF-new');

    expect(fn () => app(BeelManager::class)->company()->invoices->storePdf('inv-1', 'a.pdf', disk: 's3-test'))
        ->toThrow(DocumentAlreadyExists::class);
    Http::assertNothingSent();

    app(BeelManager::class)->company()->invoices->storePdf('inv-1', 'a.pdf', disk: 's3-test', overwrite: true);

    expect($bucket->objects)->toBe(['a.pdf' => ['body' => '%PDF-new', 'type' => 'application/pdf']]);
});

it('never replaces the S3 object with a download that fails verification', function () {
    $bucket = new FakeS3Bucket;
    $bucket->objects['a.pdf'] = ['body' => 'previous', 'type' => 'application/pdf'];
    s3Disk($bucket);
    BeelFake::api()->invoicePdf('<Error>AccessDenied</Error>')->fake();

    expect(fn () => app(BeelManager::class)->company()->invoices->storePdf('inv-1', 'a.pdf', disk: 's3-test', overwrite: true))
        ->toThrow(DocumentDownloadFailed::class, 'not a PDF');

    expect($bucket->objects)->toBe(['a.pdf' => ['body' => 'previous', 'type' => 'application/pdf']]);
});

it('stores the PDF on an in-memory disk, overwriting only when asked', function () {
    memoryDisk();
    fakeBeelPdf('%PDF-memory');
    $invoices = app(BeelManager::class)->company()->invoices;

    $invoices->storePdf('inv-1', 'a.pdf', disk: 'memory-test');
    expect(fn () => $invoices->storePdf('inv-1', 'a.pdf', disk: 'memory-test'))->toThrow(DocumentAlreadyExists::class);
    $invoices->storePdf('inv-1', 'a.pdf', disk: 'memory-test', overwrite: true);

    expect(Storage::disk('memory-test')->get('a.pdf'))->toBe('%PDF-memory')
        ->and(Storage::disk('memory-test')->allFiles())->toBe(['a.pdf']);
});
