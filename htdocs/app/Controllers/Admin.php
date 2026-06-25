<?php

namespace App\Controllers;

class Admin extends BaseController
{
    public function index()
    {
        // 🔒 Seguridad robusta
        $session = session();

        $isLoggedIn = $session->get('isLoggedIn') ?? false;
        $role       = $session->get('role') ?? null;

        if (!$isLoggedIn || $role !== 'admin') {
            return redirect()
                ->to('/login')
                ->with('error', 'Acceso no autorizado.');
        }

        $db = \Config\Database::connect();

        // 🔄 Procesamiento POST (CRUD)
        if ($this->request->is('post')) {

            $accion = $this->request->getPost('accion');
            $userId = (int) ($this->request->getPost('user_id') ?? 0);

            if ($userId > 0) {

                if ($accion === 'cambiar_rol') {

    $rol = strtolower(trim($this->request->getPost('rol') ?? ''));

    if (!in_array($rol, ['admin', 'user'])) {
        $rol = 'user';
    }

    $updated = $db->table('users')
        ->where('id', $userId)
        ->update([
            'role' => $rol,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

    if ($updated) {
        $session->setFlashdata('success', 'Rol actualizado correctamente.');
    } else {
        $session->setFlashdata('error', 'No se pudo actualizar el rol.');
    }
}

                // 🔁 Cambiar estado
                if ($accion === 'cambiar_estado') {

                    $estado = $this->request->getPost('estado');

                    $estadosValidos = ['pending', 'active', 'suspended', 'deleted'];

                    if (in_array($estado, $estadosValidos)) {

                        $db->table('users')
                            ->where('id', $userId)
                            ->update([
                                'status' => $estado,
                                'updated_at' => date('Y-m-d H:i:s')
                            ]);

                        $session->setFlashdata('success', 'Estado actualizado correctamente.');
                    }
                }
            }
        }

        // 📊 Datos para vista
        $data = [
            'pageTitle' => 'TurSegInfo | Panel Admin',

            'usuarios' => $db->table('users u')
                ->select('u.id, u.email, u.status, u.role, p.display_name, p.username')
                ->join('user_profiles p', 'p.user_id = u.id', 'left')
                ->orderBy('u.created_at', 'DESC')
                ->limit(50)
                ->get()
                ->getResultArray(),

            'lugares' => $db->table('places p')
                ->select('p.id, p.name, p.status, p.moderation_status, c.city_name')
                ->join('cities c', 'c.id = p.city_id', 'left')
                ->orderBy('p.created_at', 'DESC')
                ->limit(20)
                ->get()
                ->getResultArray()
        ];

        return view('templates/header', $data)
            . view('admin/index', $data)
            . view('templates/footer');
    }
}