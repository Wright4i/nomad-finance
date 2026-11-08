type Marked = {
  assetFavorite: boolean;
  side: string;
  archivedAt: string | null;
};

export function payFromAccount<T extends Marked>(accounts: T[]): T | undefined {
  return accounts.find((account) => account.archivedAt === null && account.assetFavorite && account.side === 'asset');
}

export function billAccount<T extends Marked>(accounts: T[]): T | undefined {
  const open = accounts.filter((account) => account.archivedAt === null);
  return open.find((account) => account.assetFavorite) ?? open[0];
}
