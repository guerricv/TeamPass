<?php

declare(strict_types=1);

/**
 * Teampass - a collaborative passwords manager.
 * Copyright (c) 2009-2026 Teampass.net
 * Licensed under the GNU General Public License, version 3 or later.
 * See <https://www.gnu.org/licenses/>.
 */

use Symfony\Component\HttpFoundation\Request;
use TeampassClasses\ConfigManager\ConfigManager;

require_once __DIR__ . '/../../Model/SecureSendModel.php';

/** JWT-authenticated Secure Send transport; policy and crypto stay in shared helpers. */
class SecureSendController extends BaseController
{
    /** Create a snapshot or an independent note from a JSON body. */
    public function createAction(array $userData): void
    {
        $this->dispatch('create', 'POST', $userData);
    }

    /** List the authenticated sender's metadata without link credentials. */
    public function listAction(array $userData): void
    {
        $this->dispatch('list', 'GET', $userData);
    }

    /** Revoke a send by id after checking ownership in the storage transaction. */
    public function revokeAction(array $userData): void
    {
        $this->dispatch('revoke', 'DELETE', $userData);
    }

    /** Enforce transport/API grants and translate domain failures to problem documents. */
    private function dispatch(string $action, string $method, array $user): void
    {
        header('Cache-Control: no-store');
        $request = $this->request();
        if ($request->getMethod() !== $method) {
            $this->sendProblem(405, 'Method not allowed', ['Allow: ' . $method]);
            return;
        }
        $model = $this->model();
        if (!$model->isAllowed($user, $action)) {
            $this->sendProblem(403, 'Access denied: insufficient permissions for this action');
            return;
        }
        try {
            $settings = $this->settings();
            if ($action === 'create') {
                if ($request->query->count() > 0 || $request->getContentTypeFormat() !== 'json') {
                    $this->sendProblem(400, 'Use a JSON request body without query parameters');
                    return;
                }
                try { $input = $request->toArray(); }
                catch (Throwable $e) { $this->sendProblem(400, 'Invalid JSON body'); return; }
                $key = null;
                if ($request->headers->has('Idempotency-Key')) {
                    try { $key = ApiIdempotencyModel::validateKey((string) $request->headers->get('Idempotency-Key')); }
                    catch (InvalidArgumentException $e) { $this->sendProblem(400, $e->getMessage()); return; }
                }
                $result = $model->create($model->normalizeCreation($input), $user, $settings, $key);
                if ($result['status'] === 409) {
                    $this->sendProblem(409, 'Idempotency key conflicts with another request or is still processing', $result['headers']);
                    return;
                }
                $this->markApiFunctionalActivity($user);
                $this->sendOutput(json_encode($result['body'], JSON_THROW_ON_ERROR), array_merge(
                    ['Content-Type: application/json; charset=UTF-8', $this->statusLine($result['status'])], $result['headers']));
            } elseif ($action === 'list') {
                $limit = $this->unsignedParameter($request->query->all(), 'limit', 50, 1, 500);
                $offset = $this->unsignedParameter($request->query->all(), 'offset', 0, 0, PHP_INT_MAX);
                $result = $model->listOwned((int) $user['id'], $settings, $limit, $offset);
                $this->markApiFunctionalActivity($user);
                $this->sendOutput(json_encode($result['sends'], JSON_THROW_ON_ERROR),
                    [$this->statusLine(200), 'X-Total-Count: ' . $result['total']]);
            } else {
                $id = $this->unsignedParameter($request->query->all(), 'id', null, 1, PHP_INT_MAX);
                if (!$model->revoke($id, (int) $user['id'], $settings)) {
                    $this->sendProblem(404, 'Secure Send not found');
                    return;
                }
                $this->markApiFunctionalActivity($user);
                $this->sendOutput(json_encode(['id' => $id, 'revoked' => true]), [$this->statusLine(200)]);
            }
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
            $status = in_array($error, ['not_allowed', 'notes_not_allowed'], true) ? 403 : 422;
            if ($error === 'invalid_parameter') { $status = 400; }
            $this->sendProblem($status, in_array($error, [
                'not_allowed', 'notes_not_allowed', 'passphrase_required', 'invalid_payload',
                'empty_note', 'cannot_decrypt', 'totp_unusable', 'invalid_parameter', 'invalid_public_url',
            ], true) ? $error : 'Invalid Secure Send request');
        } catch (Throwable $e) {
            error_log('TEAMPASS Secure Send API failed (' . get_class($e) . ')');
            $this->sendProblem(500, 'Unable to process Secure Send request');
        }
    }

    /** Read a bounded unsigned query parameter without PHP's permissive numeric casts. */
    private function unsignedParameter(array $input, string $name, ?int $default, int $min, int $max): int
    {
        if (!array_key_exists($name, $input) && $default !== null) { return $default; }
        $raw = $input[$name] ?? null;
        if (!is_string($raw) || preg_match('/^(0|[1-9][0-9]*)$/D', $raw) !== 1) {
            throw new InvalidArgumentException('invalid_parameter');
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($value === false) { throw new InvalidArgumentException('invalid_parameter'); }
        return $value;
    }

    /** Resolve current settings (replaceable in isolated transport tests). */
    protected function settings(): array
    {
        return (new ConfigManager())->getAllSettings();
    }

    /** Construct the API business adapter (replaceable in transport tests). */
    protected function model(): SecureSendModel
    {
        return new SecureSendModel();
    }

    /** Read the current HTTP request (replaceable in isolated transport tests). */
    protected function request(): Request
    {
        return Request::createFromGlobals();
    }
}
