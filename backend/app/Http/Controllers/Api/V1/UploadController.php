<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    /**
     * Upload product image
     */
    public function productImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'], // 5MB max
        ], [
            'image.required' => 'A imagem é obrigatória',
            'image.mimes' => 'A imagem deve ser JPG, PNG, GIF ou WebP',
            'image.max' => 'A imagem deve ter no máximo 5MB',
        ]);

        $file = $request->file('image');
        $accountId = $request->user()->account_id;
        
        // Generate unique filename
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid()->toString() . '.' . $extension;
        
        // Store in public disk under products folder
        $path = $file->storeAs("products/{$accountId}", $filename, 'public');
        
        // Generate public URL using request host for consistency with frontend
        $url = $request->getSchemeAndHttpHost() . '/storage/' . $path;

        return response()->json([
            'success' => true,
            'data' => [
                'url' => $url,
                'path' => $path,
                'filename' => $filename,
            ],
        ]);
    }

    /**
     * Upload generic file (for various purposes)
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,gif,webp,pdf', 'max:10240'], // 10MB max
            'folder' => ['nullable', 'string', 'max:100'],
        ], [
            'file.required' => 'O arquivo é obrigatório',
            'file.mimes' => 'Formato de arquivo não permitido',
            'file.max' => 'O arquivo deve ter no máximo 10MB',
        ]);

        $file = $request->file('file');
        $accountId = $request->user()->account_id;
        $folder = $request->input('folder', 'uploads');

        // Sanitize folder to prevent path traversal
        $folder = preg_replace('/[^a-zA-Z0-9_\-]/', '', $folder);
        if (empty($folder)) {
            $folder = 'uploads';
        }
        
        // Generate unique filename
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid()->toString() . '.' . $extension;
        
        // Store in public disk
        $path = $file->storeAs("{$folder}/{$accountId}", $filename, 'public');
        
        // Generate public URL using request host for consistency with frontend
        $url = $request->getSchemeAndHttpHost() . '/storage/' . $path;

        return response()->json([
            'success' => true,
            'data' => [
                'url' => $url,
                'path' => $path,
                'filename' => $filename,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ],
        ]);
    }

    /**
     * Delete uploaded file
     */
    public function delete(Request $request): JsonResponse
    {
        $request->validate([
            'path' => ['required', 'string'],
        ]);

        $path = $request->input('path');
        $accountId = $request->user()->account_id;
        
        // Security check - only allow deleting files from user's account folder
        if (!str_contains($path, "/{$accountId}/")) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Não autorizado a deletar este arquivo',
                ],
            ], 403);
        }

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        return response()->json([
            'success' => true,
            'message' => 'Arquivo deletado com sucesso',
        ]);
    }
}
