<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Helper;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Exceptions\SchemaException;
use Opis\JsonSchema\Uri;
use Opis\JsonSchema\Validator;

/**
 * Validates data against a JSON schema using opis/json-schema.
 *
 * @internal
 */
class SchemaValidator
{
    /**
     * Checks that the JSON schema located at $schemaPath exists and can be parsed by opis/json-schema.
     *
     * This goes beyond a plain JSON syntax check: the schema is loaded and a dummy document is
     * validated against it, which forces opis to parse the schema. Structural errors, an
     * unsupported draft, and broken references reached while validating the dummy document
     * therefore surface here. Note that references are resolved lazily by opis, so a broken
     * "$ref" that is not reached by the dummy document may not be detected.
     *
     * @throws \RuntimeException if the schema file is missing, is not valid JSON, is not a JSON
     *                           schema, or cannot be parsed
     */
    public static function validateSchema(string $schemaPath): void
    {
        $schemaRealPath = realpath($schemaPath);
        if ($schemaRealPath === false) {
            throw new \RuntimeException(sprintf('Schema file not found: %s', $schemaPath));
        }

        $contents = file_get_contents($schemaRealPath);
        if (!is_string($contents)) {
            throw new \RuntimeException(sprintf('Failed to read schema file: %s', $schemaPath));
        }

        try {
            $schema = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Failed to parse schema file %s: %s', $schemaPath, $e->getMessage()));
        }

        // A JSON schema is either an object or a boolean; anything else is not a schema at all.
        if (!is_object($schema) && !is_bool($schema)) {
            throw new \RuntimeException(sprintf('Not a valid JSON schema (must be an object or boolean): %s', $schemaPath));
        }

        // Validating a dummy document forces opis to parse the schema, so structural errors and
        // an unsupported draft are detected.
        try {
            self::createValidator()->validate(new \stdClass(), 'file://'.$schemaRealPath);
        } catch (SchemaException $e) {
            throw new \RuntimeException(sprintf('Failed to parse schema %s: %s', $schemaPath, $e->getMessage()));
        }
    }

    /**
     * Validates the given data against the JSON schema located at $schemaPath.
     *
     * @return array<string, string> a map of property path to error message, empty if the data is valid
     */
    public static function validateJsonSchemaData(mixed $data, string $schemaPath): array
    {
        $schemaRealPath = realpath($schemaPath);
        if ($schemaRealPath === false) {
            throw new \RuntimeException(sprintf('Schema file not found: %s', $schemaPath));
        }

        $result = self::createValidator()->validate($data, 'file://'.$schemaRealPath);
        if ($result->isValid()) {
            return [];
        }

        $error = $result->error();
        if (!$error instanceof ValidationError) {
            return ['$' => 'JSON schema validation failed'];
        }

        $keyed = (new ErrorFormatter())->formatKeyed(
            $error,
            null, // default message formatter
            static function (ValidationError $error): string {
                $path = $error->data()->fullPath();
                if ($path === []) {
                    return '$';
                }

                $formatted = '';
                foreach ($path as $segment) {
                    $formatted .= is_int($segment) ? sprintf('[%d]', $segment) : ($formatted === '' ? $segment : '.'.$segment);
                }

                return $formatted;
            }
        );

        return array_map(static fn (array $errors): string => implode(', ', $errors), $keyed);
    }

    private static function createValidator(): Validator
    {
        $validator = new Validator();
        // Report more than just the first violation so the returned map can list every problem.
        $validator->setMaxErrors(25);
        // Resolve file:// schema URIs (including relative $ref between schema files) by loading them from disk.
        $validator->resolver()->registerProtocol('file', static function (Uri $uri): mixed {
            $contents = file_get_contents($uri->path());
            if (!is_string($contents)) {
                return null;
            }

            $schema = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
            // opis requires a schema's "$id" to be an absolute URI, but the spec allows a relative
            // reference (which must be resolved against the base URI). Resolve it against the file
            // location so relative and non-URL "$id" values are supported.
            if (is_object($schema) && is_string($schema->{'$id'} ?? null)) {
                $schema->{'$id'} = (string) Uri::merge($schema->{'$id'}, $uri, true);
            }

            return $schema;
        });

        return $validator;
    }
}
