<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\AmcContract;
use App\Support\{AmcAccess, AmcAudit};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Storage};

final class ContractDocumentsController extends Controller
{
    private function contract(Request $request, int $id): AmcContract
    {
        abort_unless(AmcAccess::isAdmin($request->user()), 403);
        return AmcAccess::scopeCustomer(AmcContract::query(), $request->user())->findOrFail($id);
    }

    public function index(Request $request, int $id)
    {
        $this->contract($request, $id);
        return response()->json(['data' => DB::table('contract_documents')->where('amc_contract_id', $id)
            ->orderByDesc('id')->get(['id', 'file_name', 'mime_type', 'file_size', 'created_at'])])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, int $id)
    {
        $contract = $this->contract($request, $id);
        $request->validate(['file' => ['required', 'file', 'max:10240',
            'extensions:pdf,doc,docx,odt,rtf,txt', 'mimes:pdf,doc,docx,odt,rtf,txt']]);
        abort_unless(config('amc.attachments_enabled', false), 503, 'Attachment uploads are disabled on this installation.');
        $file = $request->file('file');
        $path = $file->store("contract-documents/{$id}", 'local');
        abort_unless(is_string($path) && $path !== '', 500, 'Document could not be stored.');
        try {
            $document = DB::transaction(function () use ($file, $path, $contract, $request) {
                $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '_', $file->getClientOriginalName()) ?: 'document';
                $record = [
                    'amc_contract_id' => $contract->id, 'uploaded_by' => \App\Support\AmcAudit::actorId($request),
                    'file_name' => mb_substr($name, 0, 200), 'file_path' => $path,
                    'mime_type' => $file->getMimeType(), 'file_size' => $file->getSize(),
                    'created_at' => now(), 'updated_at' => now(),
                ];
                $documentId = DB::table('contract_documents')->insertGetId($record);
                AmcAudit::record($request, 'contract.document_uploaded', $contract, ['document_id' => $documentId]);
                return ['id' => $documentId, ...array_intersect_key($record, array_flip(['file_name', 'mime_type', 'file_size', 'created_at']))];
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        return response()->json(['data' => $document], 201);
    }

    public function show(Request $request, int $id, int $document)
    {
        $this->contract($request, $id);
        $request->validate(['download' => ['sometimes', 'boolean']]);
        $file = DB::table('contract_documents')->where('amc_contract_id', $id)->where('id', $document)->first();
        abort_unless($file && Storage::disk('local')->exists($file->file_path), 404);
        $headers = ['Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox"];
        if ($file->mime_type === 'application/pdf' && ! $request->boolean('download')) {
            return Storage::disk('local')->response($file->file_path, $file->file_name, $headers, 'inline');
        }
        return Storage::disk('local')->download($file->file_path, $file->file_name, $headers);
    }
}
