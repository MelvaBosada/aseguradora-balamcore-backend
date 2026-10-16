<?php

namespace App\Http\Controllers;

use Firebase\JWT\JWT;
use Illuminate\Http\Request;

class MetabaseController extends Controller
{
    public function embedUrl(Request $request)
    {
        $cfg = config('services.metabase');

        $payload = [
            'resource' => ['dashboard' => $cfg['dashboard_id']],
            'params'   => ['id_aseguradora' => $request->user()->id_aseguradora],
            'exp'      => time() + 600, // 10 minutos
        ];

        $token = JWT::encode($payload, $cfg['secret'], 'HS256');

        return response()->json([
            'url' => "{$cfg['url']}/embed/dashboard/{$token}#bordered=false&titled=false",
        ]);
    }
}