<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ClusterController;
use App\Http\Controllers\Api\NewsItemController;
use App\Http\Controllers\Api\PublicationController;
use App\Http\Middleware\ApiTokenAuth;
use Illuminate\Support\Facades\Route;

Route::middleware(ApiTokenAuth::class)->group(function () {
    Route::get('/clusters', [ClusterController::class, 'index']);
    Route::get('/clusters/{cluster}', [ClusterController::class, 'show']);
    Route::post('/clusters/{cluster}/generate/linkedin', [ClusterController::class, 'generateLinkedIn']);
    Route::post('/clusters/{cluster}/generate/article', [ClusterController::class, 'generateArticle']);

    Route::get('/news-items', [NewsItemController::class, 'index']);

    Route::get('/publications', [PublicationController::class, 'index']);
    Route::patch('/publications/{publication}', [PublicationController::class, 'update']);
    Route::get('/publications/{publication}/export', [PublicationController::class, 'export']);
});
