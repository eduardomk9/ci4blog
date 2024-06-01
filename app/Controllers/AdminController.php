<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class AdminController extends BaseController
{
    public function index()
    {
        $data =[
            'pageTitle'=>'Dashboard'
        ];
        return view('backend/pages/home', $data);
    }
}
