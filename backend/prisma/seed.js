import 'dotenv/config';
import { PrismaClient } from '@prisma/client';
import { PrismaPg } from '@prisma/adapter-pg';
import pg from 'pg';
import bcrypt from 'bcrypt';

const { Pool } = pg;
const pool = new Pool({ connectionString: process.env.DATABASE_URL });
const adapter = new PrismaPg(pool);
const prisma = new PrismaClient({ adapter });

async function main() {
  const hashedPassword = await bcrypt.hash('password123', 10);

  // Create Admin
  const admin = await prisma.user.upsert({
    where: { userId: 'admin001' },
    update: {},
    create: {
      userId: 'admin001',
      name: 'Admin User',
      password: hashedPassword,
      role: 'ADMIN'
    }
  });
  
  await prisma.admin.upsert({
    where: { userId: admin.id },
    update: {},
    create: {
      userId: admin.id,
      adminId: 'admin001'
    }
  });
  console.log(`Created admin: admin001`);

  // Create Lecturer
  const lecturer = await prisma.user.upsert({
    where: { userId: 'lecturer001' },
    update: {},
    create: {
      userId: 'lecturer001',
      name: 'John Lecturer',
      password: hashedPassword,
      role: 'LECTURER'
    }
  });
  
  await prisma.lecturer.upsert({
    where: { userId: lecturer.id },
    update: {},
    create: {
      userId: lecturer.id,
      lecturerId: 'lecturer001',
      department: 'Computer Science'
    }
  });
  console.log(`Created lecturer: lecturer001`);

  // Create Student
  const student = await prisma.user.upsert({
    where: { userId: 'student001' },
    update: {},
    create: {
      userId: 'student001',
      name: 'Jane Student',
      password: hashedPassword,
      role: 'STUDENT'
    }
  });
  
  await prisma.student.upsert({
    where: { userId: student.id },
    update: {},
    create: {
      userId: student.id,
      studentId: 'student001',
      level: '300',
      academicYear: '2025/2026'
    }
  });
  console.log(`Created student: student001`);

  console.log('\n✅ Seed completed!');
  console.log('\nLogin credentials:');
  console.log('Admin: admin001 / password123');
  console.log('Lecturer: lecturer001 / password123');
  console.log('Student: student001 / password123');
}

main()
  .catch((e) => {
    console.error(e);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
