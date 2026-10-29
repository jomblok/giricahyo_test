<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // CORS — izinkan request dari frontend React
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
            // Di depan middleware lain (hanya setelah CORS) supaya angka
            // "total"-nya mencakup pembatas laju yang membaca cache.
            \App\Http\Middleware\ServerTiming::class,
        ]);

        // Header keamanan pada SEMUA respons /api. Sebelumnya tidak ada satu
        // pun — lihat alasan tiap header di App\Http\Middleware\SecurityHeaders.
        $middleware->api(append: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        // Batas laju untuk semua route /api (limiter 'api' didefinisikan di
        // AppServiceProvider). Laravel 11 tidak memasang ini sendiri.
        $middleware->throttleApi();

        // Alias middleware untuk JWT
        $middleware->alias([
            'jwt' => \App\Http\Middleware\JwtMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Return JSON untuk semua error di API
        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->is('api/*')) {
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    return response()->json([
                        'message' => 'Data yang dikirim tidak valid.',
                        'errors'  => $e->errors(),
                    ], 422);
                }
                if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                    // ModelNotFoundException DIPERIKSA LEWAT getPrevious(), bukan
                    // langsung. Laravel sudah mengubahnya menjadi
                    // NotFoundHttpException (prepareException) SEBELUM callback
                    // ini dipanggil, jadi `$e instanceof ModelNotFoundException`
                    // tidak pernah benar. Cabang seperti itu dulu ada di sini dan
                    // tidak pernah jalan: 404 jatuh ke baris di bawah dan
                    // membalas "No query results for model [App\Models\Tree] X"
                    // — nama kelas internal, ke siapa pun, termasuk lewat
                    // endpoint publik pohon yang dibuka tanpa login. Itu terjadi
                    // juga saat APP_DEBUG=false, karena pesan HttpException
                    // dianggap aman untuk diteruskan.
                    if ($e->getPrevious() instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                        return response()->json(['message' => 'Data tidak ditemukan.'], 404);
                    }
                    return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
                }
            }
        });
    })
    ->create();
