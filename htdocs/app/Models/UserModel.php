<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $allowedFields    = ['email', 'password_hash', 'date_of_birth', 'email_verified_at', 'status', 'role'];
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    /**
     * Registra un nuevo usuario insertando en 'users' y 'user_profiles' bajo una transacción.
     */
    public function registrarUsuario(array $userData, array $profileData)
    {
        $this->db->transStart();

        $this->insert($userData);
        $profileData['user_id'] = $this->insertID();

        $this->db->table('user_profiles')->insert($profileData);

        $this->db->transComplete();
        return $this->db->transStatus();
    }

    /**
     * Obtiene el perfil completo del usuario para la sesión y dashboard.
     */
    public function getPerfilCompleto($email)
    {
        return $this->select('users.*, user_profiles.display_name, user_profiles.username, cities.city_name')
            ->join('user_profiles', 'user_profiles.user_id = users.id', 'left')
            ->join('cities', 'cities.id = user_profiles.city_id', 'left')
            ->where('users.email', $email)
            ->first();
    }
}