export function hasAdminPermission(user: { role?: string; permissions?: string[] } | null, permission?: string): boolean {
  if (!permission) return true;
  if (user?.role === 'owner') return true;

  const permissions = user?.permissions ?? [];
  if (permissions.includes('*')) return true;
  if (permissions.includes(permission)) return true;

  const dot = permission.replace(/:/g, '.');
  const colon = permission.replace(/\./g, ':');
  if (permissions.includes(dot) || permissions.includes(colon)) return true;

  const category = dot.split('.')[0];
  return permissions.includes(`${category}.*`) || permissions.includes(`${category}:*`);
}
