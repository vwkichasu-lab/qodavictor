import express from "express";
import cors from "cors";
import helmet from "helmet";
import routes from "./routes/index.js";

const app = express();

// Middleware
app.use(cors());
app.use(helmet());
app.use(express.json({ limit: '10mb' }));
app.use(express.urlencoded({ extended: true, limit: '10mb' }));

// API Routes
app.use("/api", routes);

// Health check endpoint
app.get("/", (req, res) => {
  res.json({ 
    message: "Qoda API is running", 
    status: "OK",
    version: "2.0.0",
    timestamp: new Date().toISOString()
  });
});

// 404 handler
app.use((req, res) => {
  res.status(404).json({ error: 'Route not found' });
});

// Error handler
app.use((err, req, res, next) => {
  console.error(err.stack);
  res.status(500).json({ 
    error: 'Internal server error',
    message: process.env.NODE_ENV === 'development' ? err.message : undefined
  });
});

export default app;
