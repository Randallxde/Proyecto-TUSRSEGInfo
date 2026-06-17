<?php

namespace App\Controllers;

use App\Models\UserModel;
use App\Models\PlaceModel;

class Auth extends BaseController
{
    protected $userModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
    }

    public function index()
    {
        $placeModel = new PlaceModel();
        // Simulación de conteos del antiguo conteo_dashboard()
        $db = \Config\Database::connect();
        
        $data = [
            'pageTitle'  => 'TurSegInfo | Inicio',
            'destacados' => $placeModel->orderBy('id', 'DESC')->findAll(8),
            'conteos'    => [
                'usuarios'    => $db->table('users')->countAllResults(),
                'sitios'      => $db->table('places')->countAllResults(),
                'comentarios' => 4, // Estático para este ejemplo
            ]
        ];

        echo view('templates/header', $data);
        echo view('index', $data);
        echo view('templates/footer');
    }

    public function register()
    {
        if (session()->get('isLoggedIn')) {
if ($usuario['role'] === 'admin') {
    return redirect()->to('/dashboard');
}

return redirect()->to('/dashboard');
        }

        $data = ['pageTitle' => 'TurSegInfo | Registro'];

        if ($this->request->is('post')) {
            $email = $this->request->getPost('email');
            
            // LÓGICA DE FILTRADO POR DOMINIO DE CORREO
            if (str_ends_with($email, '@soy.sena.edu.co')) {
                $role = 'admin';
            } elseif (str_ends_with($email, '@gmail.com')) {
                $role = 'user';
            } else {
                return redirect()->back()->withInput()->with('error', 'El dominio del correo debe terminar en @gmail.com (usuarios) o @soy.sena.edu.co (administradores).');
            }

            // Reglas de validación nativas de CodeIgniter 4
            $rules = [
                'display_name' => 'required|min_length[3]',
                'username'     => 'required|alpha_dash|min_length[3]|is_unique[user_profiles.username]',
                'email'        => 'required|valid_email|is_unique[users.email]',
                'password'     => 'required|min_length[8]',
                'password_confirmation' => 'required|matches[password]',
                'date_of_birth'=> 'required'
            ];

            if (!$this->validate($rules)) {
                return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
            }

            $userData = [
                'email'         => $email,
                'password_hash' => password_hash($this->request->getPost('password'), PASSWORD_DEFAULT),
                'date_of_birth' => $this->request->getPost('date_of_birth'),
                'status'        => 'active',
                'role'          => $role
            ];

            $profileData = [
                'username'     => $this->request->getPost('username'),
                'display_name' => $this->request->getPost('display_name'),
                'profile_visibility'  => 'public',
                'location_visibility' => 'friends'
            ];

            if ($this->userModel->registrarUsuario($userData, $profileData)) {
                $sessionData = [
                    'email'       => $userData['email'],
                    'role'        => $userData['role'],
                    'displayName' => $profileData['display_name'],
                    'isLoggedIn'  => true
                ];
                session()->set($sessionData);
                
                return redirect()->to($role === 'admin' ? '/admin' : '/dashboard')->with('success', 'Registro completado.');
            }

            return redirect()->back()->with('error', 'Ocurrió un error en la base de datos.');
        }

        echo view('templates/header', $data);
        echo view('auth/register');
        echo view('templates/footer');
    }

    public function login()
    {
        if (session()->get('isLoggedIn')) {
            return redirect()->to(session()->get('role') === 'admin' ? '/admin' : '/dashboard');
        }

        $data = ['pageTitle' => 'TurSegInfo | Iniciar Sesión'];

        if ($this->request->is('post')) {
            $email = $this->request->getPost('email');
            $password = $this->request->getPost('password');

            $usuario = $this->userModel->getPerfilCompleto($email);

            if (!$usuario || !password_verify($password, $usuario['password_hash'])) {
                return redirect()->back()->with('error', 'Credenciales incorrectas.');
            }

            if ($usuario['status'] !== 'active') {
                return redirect()->back()->with('error', 'Tu cuenta no está activa.');
            }

            session()->set([
                'id'          => $usuario['id'],
                'email'       => $usuario['email'],
                'role'        => $usuario['role'],
                'displayName' => $usuario['display_name'] ?? $usuario['username'],
                'isLoggedIn'  => true
            ]);

            return redirect()->to($usuario['role'] === 'admin' ? '/admin' : '/dashboard');
        }

        echo view('templates/header', $data);
        echo view('auth/login');
        echo view('templates/footer');
    }

    public function logout()
    {
        session()->destroy();
        return redirect()->to('/login')->with('success', 'Sesión cerrada correctamente.');
    }
}