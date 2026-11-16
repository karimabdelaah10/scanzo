<?php

namespace App\Http\Controllers\Api;

use App\Actions\Catalogue\ImportCatalogue;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ImportCatalogueRequest;
use App\Support\Import\CatalogueFileReader;
use App\Support\Import\ImportTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CatalogueImportController extends Controller
{
    /** POST /api/catalogue/import — multipart: file, branch_id? */
    public function store(ImportCatalogueRequest $request, ImportCatalogue $import): JsonResponse
    {
        $summary = $import->handle(
            user: $request->user(),
            path: $request->file('file')->getRealPath(),
            defaultBranchId: $request->input('branch_id') ?: null,
        );

        return response()->json(['data' => $summary], 201);
    }

    /**
     * GET /api/catalogue/import/template?format=xlsx|csv
     *
     * Without the spreadsheet package the .xlsx template cannot be built, so
     * the CSV one is served and the file name says which it is.
     */
    public function template(Request $request): StreamedResponse
    {
        if (Gate::denies('manage-catalogue')) {
            throw DomainException::notAllowedToManageCatalogue();
        }

        $wantsCsv = $request->query('format') === 'csv';

        return $wantsCsv || ! CatalogueFileReader::xlsxAvailable()
            ? ImportTemplate::csv()
            : ImportTemplate::xlsx();
    }
}
