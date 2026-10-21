// XAMPP local. Para Docker: BACKEND_ORIGIN=http://localhost:8080.
module.exports = {
  '/backend/**': {
    target: process.env.BACKEND_ORIGIN || 'http://localhost/zentrax-project/03-proyecto-zemyna/programacion',
    changeOrigin: true,
  },
};
