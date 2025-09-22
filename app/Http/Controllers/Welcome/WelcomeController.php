<?php

namespace App\Http\Controllers\Welcome;

use App\Http\Controllers\Controller;
use App\Repositories\ProductRepository;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class WelcomeController extends Controller
{
     /**
     * Response trait to handle return responses.
     */
    use ResponseTrait;

    public function welcome()
    {
    return $this->responseSuccess([], 'Holla /: this is base API, developed by Deraly Team, reads the documentation for usage', 200);
    }
}
