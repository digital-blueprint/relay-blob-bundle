<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Controller;

use Symfony\Component\HttpFoundation\Request;

final class CreateFileAction extends BaseBlobController
{
    public static function requestGet(Request $request, string $key, $default = null)
    {
        if ($request->query->has($key)) {
            return $request->query->all()[$key];
        }

        if ($request->request->has($key)) {
            return $request->request->all()[$key];
        }

        return $default;
    }
}
