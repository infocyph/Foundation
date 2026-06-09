# Foundation

`Foundation` is the Infocyph integration layer.

It does not replace the standalone packages. It wires them into one application-oriented stack with:

- application bootstrap
- provider lifecycle
- shared configuration
- InterMix container integration
- AuthLayer composition

Current implementation focus:

- package shell and Composer metadata
- application/bootstrap core
- config repository and config loading
- first `AuthLayer` integration with in-memory defaults

