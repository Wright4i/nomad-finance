export function accountLabel(account: {
  name: string
  institution?: string | null
  holder?: string | null
}): string {
  const name = account.name.trim()
  const institution = account.institution?.trim() ?? ''
  const holder = account.holder?.trim() ?? ''
  const named = institution === '' ? name : `${name} - ${institution}`

  return holder === '' ? named : `${named} (${holder})`
}
