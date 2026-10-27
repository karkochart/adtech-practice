<?php

namespace App\Http\Controllers;

class TestController extends Controller
{
    public function test()
    {
        return response()->json(['message' => 'Test route is working!']);
    }
}
