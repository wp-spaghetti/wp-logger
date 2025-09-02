export default {
  extends: ['@commitlint/config-conventional'],
  ignores: [
    (commit) => /\[skip ci\]/m.test(commit),
  ],
}