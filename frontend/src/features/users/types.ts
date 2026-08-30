// Kullanıcı Yönetimi modülü tipleri — backend sözleşmesiyle birebir eşleşir
// (bkz. Faz 2 / Dalga 1 / E görev tanımı).

export type Role = {
  id: number
  name: string
  permissions?: string[]
}

export type User = {
  id: number
  name: string
  email: string
  department: string | null
  is_active: boolean
  must_change_password: boolean
  last_login_at: string | null
  created_at: string
  role: { id: number; name: string } | null
}

export type UsersQuery = {
  page?: number
  per_page?: number
  sort?: string
  q?: string
  role?: string
  is_active?: boolean
}
