<?php
namespace App\Controllers;
use App\Models\UsuarioModel;
class Home extends BaseController
{
// LOGIN
public function index()
{
return view('login');
}
// VISTA REGISTRO
public function registrar()
{
return view('registro');
}
// GUARDAR USUARIO
public function guardar()
{
$modelo = new UsuarioModel();
$datos = [

'nombre' => $this->request->getPost('nombre'),

'correo' => $this->request->getPost('correo'),

'password' => password_hash(
$this->request->getPost('password'),
PASSWORD_DEFAULT
),
'rol' => 'user'
];
$modelo->insert($datos);
return redirect()->to('/');
}
// LOGIN USUARIO
public function login()
{
$modelo = new UsuarioModel();
$correo = $this->request->getPost('correo');
$password = $this->request->getPost('password');
$usuario = $modelo

->where('correo', $correo)
->first();

if ($usuario) {
if (
password_verify(
$password,
$usuario['password']
)
) {
session()->set([
'id' => $usuario['id'],
'nombre' => $usuario['nombre'],
'rol' => $usuario['rol'],
'logueado' => true
]);
return redirect()->to('/dashboard');
} else {
echo "Password incorrecto";
}
} else {
echo "Usuario no existe";
}

}
// DASHBOARD
public function dashboard()
{
if (!session('logueado')) {
return redirect()->to('/');
}
return view('dashboard');
}
// LISTAR USUARIOS
public function usuarios()
{
if (session('rol') != 'admin') {
return redirect()->to('/dashboard');
}
$modelo = new UsuarioModel();
$datos['usuarios'] = $modelo->findAll();
return view('usuarios', $datos);
}
// EDITAR
public function editar($id)
{
    $modelo = new UsuarioModel();

    $usuario = $modelo->find($id);

    return view('editar_usuario', [
        'usuario' => $usuario
    ]);
}
// ACTUALIZAR
public function actualizar($id)
{
$modelo = new UsuarioModel();
$datos = [
'nombre' => $this->request->getPost('nombre'),

'correo' => $this->request->getPost('correo'),

'rol' => $this->request->getPost('rol')
];
$modelo->update($id, $datos);
return redirect()->to('/usuarios');
}
// ELIMINAR
public function eliminar($id)
{
$modelo = new UsuarioModel();

$modelo->delete($id);
return redirect()->to('/usuarios');
}
// LOGOUT
public function logout()
{
session()->destroy();
return redirect()->to('/');
}
}