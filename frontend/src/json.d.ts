declare module '*.json' {
  const value: unknown
  export default value
}

declare module '*?url' {
  const href: string
  export default href
}
