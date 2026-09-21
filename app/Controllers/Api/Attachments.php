<?php

namespace App\Controllers\Api;

use App\Repositories\AttachmentRepository;
use App\Repositories\RuleViolation;

/**
 * Supporting documents, for every module (AttachmentRepository).
 *
 *   POST attachments                  multipart `file`: keep it until a form attaches it
 *   POST attachments/{id}/discard     remove an upload that was not attached
 *   GET  attachments/{id}             download a document (from S3: a redirect to a presigned link)
 *   POST documents/{kind}/{ref}       {documents: [ids]}: attach to a record that already exists
 *
 * A form that creates a record sends the ids of its uploads as `documents`, and
 * the record's own endpoint attaches them.
 */
class Attachments extends BaseApiController
{
    public function upload()
    {
        $repo = new AttachmentRepository();
        try {
            $file = $repo->accept($this->request->getFile('file'));

            return $this->json(['document' => $repo->upload($file, $this->actorId())])->setStatusCode(201);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }

    public function discard($id)
    {
        try {
            (new AttachmentRepository())->discard((int) $id, $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['discarded' => (int) $id]);
    }

    public function download($id)
    {
        return $this->sendDocument((new AttachmentRepository())->file((int) $id, $this->actorId()), 'That document is not there, or not one you can open.');
    }

    /** Body: {documents: [ids]}. The ref is the record's reference, award ref, asset tag or count id. */
    public function attach(string $kind, string ...$ref)
    {
        $spec = AttachmentRepository::KINDS[$kind] ?? null;
        if ($spec === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Documents cannot be attached to a ' . $kind . '.']);
        }
        if (!$this->can($spec['permission'])) {
            return $this->denied($this->actor()['role'] . ' cannot add documents to ' . $spec['label'] . 's. That needs a role with ' . $spec['permission'] . '.');
        }

        try {
            $documents = (new AttachmentRepository())->attach($kind, rawurldecode(implode('/', $ref)), ($this->request->getJSON(true) ?? [])['documents'] ?? [], $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['documents' => $documents, 'message' => count($documents) . ' supporting document' . (count($documents) === 1 ? '' : 's') . ' on file.']);
    }
}
