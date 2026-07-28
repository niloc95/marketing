<?php

namespace App\Controllers;

use App\Services\DirectoryAdminService;

class Admin extends BaseController
{
    public function login()
    {
        if (session()->get('dir_admin')) {
            return redirect()->to(base_url('admin'));
        }
        return view('admin/login');
    }

    public function attemptLogin()
    {
        $password = (string) $this->request->getPost('password');
        $expected = config('Directory')->adminPassword();

        if ($expected !== '' && hash_equals($expected, $password)) {
            session()->set('dir_admin', true);
            return redirect()->to(base_url('admin'));
        }
        return redirect()->to(base_url('admin/login'))->with('error', 'Incorrect password.');
    }

    public function logout()
    {
        session()->remove('dir_admin');
        return redirect()->to(base_url('admin/login'))->with('info', 'Signed out.');
    }

    public function index()
    {
        $svc    = new DirectoryAdminService();
        $status = trim((string) $this->request->getGet('status'));
        $page   = (int) ($this->request->getGet('page') ?? 1);

        return view('admin/index', [
            'result' => $svc->list($status, $page),
            'status' => $status,
        ]);
    }

    public function feature(int $id)
    {
        (new DirectoryAdminService())->setFeatured($id, (bool) $this->request->getPost('on'));
        return $this->back('Listing updated.');
    }

    public function publish(int $id)
    {
        (new DirectoryAdminService())->publish($id);
        return $this->back('Listing published.');
    }

    public function unpublish(int $id)
    {
        (new DirectoryAdminService())->unpublish($id);
        return $this->back('Listing unpublished.');
    }

    public function remove(int $id)
    {
        (new DirectoryAdminService())->remove($id);
        return $this->back('Listing removed.');
    }

    private function back(string $message)
    {
        return redirect()->to(previous_url() ?: base_url('admin'))->with('success', $message);
    }
}
