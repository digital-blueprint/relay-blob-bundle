<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use Dbp\Relay\BlobBundle\Helper\SchemaValidator;
use PHPUnit\Framework\TestCase;

class SchemaValidatorTest extends TestCase
{
    private const SCHEMA_DIR = __DIR__.'/Fixtures/schema-validation';

    /**
     * @var string[]
     */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function decode(array $data): object
    {
        return json_decode(json_encode((object) $data, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private static function validDraft6Metadata(): array
    {
        return [
            '@type' => 'DemoDocumentV6',
            'owner' => ['id' => 'person-123'],
            'groupId' => 'aae64261-e417-4fa4-b1f5-5c5c7d0c3ba4',
            'status' => 'final',
            'approved' => true,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private function validateDraft6(array $data): array
    {
        return SchemaValidator::validateJsonSchemaData(
            self::decode($data),
            self::SCHEMA_DIR.'/demo-document-draft6.schema.json'
        );
    }

    /**
     * Writes an ad-hoc schema to a temp file and validates $data against it.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private function validateAgainstSchema(array $schema, array $data): array
    {
        $file = tempnam(sys_get_temp_dir(), 'blob-schema-').'.json';
        file_put_contents($file, json_encode($schema, JSON_THROW_ON_ERROR));
        $this->tempFiles[] = $file;

        return SchemaValidator::validateJsonSchemaData(self::decode($data), $file);
    }

    public function testValidDataReturnsNoErrors(): void
    {
        $this->assertSame([], $this->validateDraft6(self::validDraft6Metadata()));
    }

    public function testValidDataDraft7(): void
    {
        $metadata = self::validDraft6Metadata();
        $metadata['@type'] = 'DemoDocumentV7';
        $this->assertSame([], SchemaValidator::validateJsonSchemaData(
            self::decode($metadata),
            self::SCHEMA_DIR.'/demo-document-draft7.schema.json'
        ));
    }

    public function testValidDataDraft2019(): void
    {
        $metadata = self::validDraft6Metadata();
        $metadata['@type'] = 'DemoDocument2019';
        $this->assertSame([], SchemaValidator::validateJsonSchemaData(
            self::decode($metadata),
            self::SCHEMA_DIR.'/demo-document-draft2019-09.schema.json'
        ));
    }

    public function testConstErrorMessage(): void
    {
        $metadata = self::validDraft6Metadata();
        $metadata['@type'] = 'UnexpectedDocument';

        $this->assertSame(
            ['@type' => 'The data must match the const value'],
            $this->validateDraft6($metadata)
        );
    }

    public function testConstErrorMessageDraft7(): void
    {
        $metadata = self::validDraft6Metadata();
        $metadata['@type'] = 'DemoDocumentV7';
        $metadata['approved'] = false;

        $this->assertSame(
            ['approved' => 'The data must match the const value'],
            SchemaValidator::validateJsonSchemaData(
                self::decode($metadata),
                self::SCHEMA_DIR.'/demo-document-draft7.schema.json'
            )
        );
    }

    public function testRequiredPropertyErrorMessage(): void
    {
        $metadata = self::validDraft6Metadata();
        unset($metadata['groupId']);

        $this->assertSame(
            ['$' => 'The required properties (groupId) are missing'],
            $this->validateDraft6($metadata)
        );
    }

    public function testEnumErrorMessage(): void
    {
        $metadata = self::validDraft6Metadata();
        $metadata['status'] = 'bogus';

        $this->assertSame(
            ['status' => 'The data should match one item from enum'],
            $this->validateDraft6($metadata)
        );
    }

    public function testPatternErrorMessage(): void
    {
        $metadata = self::validDraft6Metadata();
        $metadata['groupId'] = 'not-a-uuid';

        $messages = $this->validateDraft6($metadata);
        $this->assertArrayHasKey('groupId', $messages);
        $this->assertStringStartsWith('The string should match pattern:', $messages['groupId']);
    }

    public function testAdditionalPropertiesErrorMessage(): void
    {
        $metadata = self::validDraft6Metadata();
        $metadata['unexpected'] = 'value';

        $this->assertSame(
            ['$' => 'Additional object properties are not allowed: unexpected'],
            $this->validateDraft6($metadata)
        );
    }

    public function testTypeErrorMessage(): void
    {
        $metadata = self::validDraft6Metadata();
        $metadata['owner'] = 'not-an-object';

        $this->assertSame(
            ['owner' => 'The data (string) must match the type: object'],
            $this->validateDraft6($metadata)
        );
    }

    public function testNestedPropertyPathInErrorKey(): void
    {
        $metadata = self::validDraft6Metadata();
        $metadata['owner'] = ['id' => 123];

        $this->assertSame(
            ['owner.id' => 'The data (integer) must match the type: string'],
            $this->validateDraft6($metadata)
        );
    }

    public function testMultipleViolationsAreAllReported(): void
    {
        $metadata = self::validDraft6Metadata();
        $metadata['@type'] = 'UnexpectedDocument';
        $metadata['status'] = 'bogus';

        $this->assertSame(
            [
                '@type' => 'The data must match the const value',
                'status' => 'The data should match one item from enum',
            ],
            $this->validateDraft6($metadata)
        );
    }

    public function testMultipleErrorsForSamePropertyAreJoined(): void
    {
        // A value failing every "anyOf" branch yields several errors for the same property path,
        // which are joined into a single message.
        $schema = [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'value' => ['anyOf' => [['type' => 'integer'], ['type' => 'boolean']]],
            ],
        ];

        $this->assertSame(
            ['value' => 'The data (string) must match the type: integer, The data (string) must match the type: boolean'],
            $this->validateAgainstSchema($schema, ['value' => 'a string'])
        );
    }

    public function testDependentRequiredErrorMessage(): void
    {
        $metadata = self::validDraft6Metadata();
        $metadata['@type'] = 'DemoDocument2019';
        $metadata['reviewer'] = 'person-456';

        $this->assertSame(
            ['$' => "'reviewedAt' property is required by 'reviewer' property"],
            SchemaValidator::validateJsonSchemaData(
                self::decode($metadata),
                self::SCHEMA_DIR.'/demo-document-draft2019-09.schema.json'
            )
        );
    }

    /**
     * opis/json-schema does not support draft-04, so validating against such a
     * schema must fail.
     */
    public function testDraft4SchemaThrows(): void
    {
        $this->expectException(\Throwable::class);

        SchemaValidator::validateJsonSchemaData(
            self::decode(self::validDraft6Metadata()),
            self::SCHEMA_DIR.'/demo-document-draft4.schema.json'
        );
    }

    public function testMissingSchemaFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Schema file not found');

        SchemaValidator::validateJsonSchemaData(self::decode([]), self::SCHEMA_DIR.'/does-not-exist.schema.json');
    }

    /**
     * A valid schema (with references to included schema files) passes validateSchema().
     */
    public function testValidateSchemaAcceptsValidSchema(): void
    {
        SchemaValidator::validateSchema(self::SCHEMA_DIR.'/demo-document-draft7.schema.json');
        $this->expectNotToPerformAssertions();
    }

    public function testValidateSchemaMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Schema file not found');

        SchemaValidator::validateSchema(self::SCHEMA_DIR.'/does-not-exist.schema.json');
    }

    public function testValidateSchemaInvalidJsonThrows(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'blob-schema-').'.json';
        file_put_contents($file, '{ not valid json');
        $this->tempFiles[] = $file;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse schema file');

        SchemaValidator::validateSchema($file);
    }

    public function testValidateSchemaNonSchemaJsonThrows(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'blob-schema-').'.json';
        file_put_contents($file, json_encode([1, 2, 3], JSON_THROW_ON_ERROR));
        $this->tempFiles[] = $file;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not a valid JSON schema');

        SchemaValidator::validateSchema($file);
    }

    /**
     * opis/json-schema does not support draft-04, so validateSchema() must reject such a schema.
     */
    public function testValidateSchemaDraft4Throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse schema');

        SchemaValidator::validateSchema(self::SCHEMA_DIR.'/demo-document-draft4.schema.json');
    }

    /**
     * A relative, non-URL "$id" must be accepted (resolved against the schema file location).
     */
    public function testRelativeSchemaIdIsSupported(): void
    {
        $schema = [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            '$id' => 'my-relative-id',
            'type' => 'object',
            'required' => ['name'],
            'properties' => ['name' => ['type' => 'string']],
        ];

        $this->assertSame([], $this->validateAgainstSchema($schema, ['name' => 'foo']));
        $this->assertSame(
            ['$' => 'The required properties (name) are missing'],
            $this->validateAgainstSchema($schema, [])
        );
    }
}
