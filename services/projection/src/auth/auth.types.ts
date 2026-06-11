// Shape of a user as resolved from the Auth Service `/api/auth/me` payload.
// Mirrors UserResource on the Laravel side. Only the fields the projection
// service actually consumes for authorisation are kept here.

export type Role = 'admin' | 'musician' | 'projectionist';

export interface AuthUser {
  id: string;
  email: string;
  display_name: string;
  roles: Role[];
}

export function isAdmin(user: AuthUser | null | undefined): boolean {
  return !!user?.roles?.includes('admin');
}
