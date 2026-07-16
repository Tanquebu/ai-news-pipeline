<?php

declare(strict_types=1);

use App\Http\Controllers\Api\BriefController;
use App\Http\Controllers\Api\ClusterController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DossierController;
use App\Http\Controllers\Api\NewsItemController;
use App\Http\Controllers\Api\PublicationController;
use App\Http\Controllers\Api\RagSearchController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Middleware\ApiTokenAuth;
use Illuminate\Support\Facades\Route;

Route::middleware(ApiTokenAuth::class)->group(function () {
    Route::get('/clusters', [ClusterController::class, 'index']);
    Route::get('/clusters/{cluster}', [ClusterController::class, 'show']);
    Route::post('/clusters/{cluster}/archive', [ClusterController::class, 'archive']);
    Route::post('/clusters/{cluster}/generate/linkedin', [ClusterController::class, 'generateLinkedIn']);
    Route::post('/clusters/{cluster}/generate/article', [ClusterController::class, 'generateArticle']);

    Route::get('/dossiers', [DossierController::class, 'index']);

    Route::get('/briefs', [BriefController::class, 'index']);
    Route::get('/briefs/{brief}', [BriefController::class, 'show'])->whereNumber('brief');
    Route::patch('/briefs/{brief}', [BriefController::class, 'update'])->whereNumber('brief');

    Route::post('/documents/ingest', [DocumentController::class, 'ingest']);
    Route::get('/documents/{document}', [DocumentController::class, 'show'])->whereNumber('document');

    Route::get('/rag/search', [RagSearchController::class, 'search']);

    Route::get('/reports', [ReportController::class, 'index']);
    Route::get('/reports/generators', [ReportController::class, 'generators']);
    Route::post('/reports/ingest', [ReportController::class, 'ingest']);
    Route::delete('/reports/{report}', [ReportController::class, 'destroy']);

    Route::get('/news-items', [NewsItemController::class, 'index']);

    Route::get('/publications', [PublicationController::class, 'index']);
    Route::patch('/publications/{publication}', [PublicationController::class, 'update']);
    Route::get('/publications/{publication}/export', [PublicationController::class, 'export']);
});
