<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Helper;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
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

        $result = $validator->validate($data, 'file://'.$schemaRealPath);
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
}
