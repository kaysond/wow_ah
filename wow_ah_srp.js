var srpClientSession = new SrpClientSession(e.modulus, e.generator, e.hash_function);
var srpClientCredentials = srpClientSession.step1(e.username, e.password, e.salt, e.public_B);
var publicA = srpClientCredentials.publicA.toString(16);
var clientEvidenceM1 = srpClientCredentials.clientEvidenceM1.toString(16);
var out = {publicA: publicA, clientEvidenceM1: clientEvidenceM1};
out;