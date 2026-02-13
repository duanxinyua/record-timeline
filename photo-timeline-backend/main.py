from fastapi import FastAPI, UploadFile, File, HTTPException, Depends, Query, Header
from fastapi.staticfiles import StaticFiles
from fastapi.middleware.cors import CORSMiddleware
from sqlmodel import Field, Session, SQLModel, create_engine, select
from typing import List, Optional
import uvicorn
import shutil
import os
import uuid
from datetime import datetime

# --- Security ---
API_SECRET = "peanut2024" # Simple hardcoded key for now

async def verify_key(x_api_key: str = Header(None)):
    if x_api_key != API_SECRET:
        raise HTTPException(status_code=403, detail="Invalid or missing API Key")
    return x_api_key

# --- Database Setup ---
class TimelineItem(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    title: str
    date: datetime
    src: str

class AppConfig(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    appTitle: str = "花生"
    kicker: str = "Peanut"
    mainTitle: str = "精彩时刻"
    subTitle: str = "记录属于你的花生时刻，支持横向滚动与本地保存。"
    timelineTitle: str = "时间轴"
    emptyText: str = "还没有照片，先上传几张吧。"
    defaultItemTitle: str = "未命名照片"
    unknownDateText: str = "未知时间"

sqlite_file_name = "timeline.db"
sqlite_url = f"sqlite:///{sqlite_file_name}"

connect_args = {"check_same_thread": False}
engine = create_engine(sqlite_url, connect_args=connect_args)

def create_db_and_tables():
    SQLModel.metadata.create_all(engine)

def get_session():
    with Session(engine) as session:
        yield session

# --- App Setup ---
app = FastAPI()

# CORS configuration
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"], 
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Directory setup
UPLOAD_DIR = "uploads"
if not os.path.exists(UPLOAD_DIR):
    os.makedirs(UPLOAD_DIR)

# Mount static files to serve images
app.mount("/uploads", StaticFiles(directory=UPLOAD_DIR), name="uploads")

@app.on_event("startup")
def on_startup():
    create_db_and_tables()

@app.get("/")
async def read_root():
    return {"message": "Peanut Timeline Backend (SQLite) is Running!"}

# --- Image Upload ---
@app.post("/upload")
async def upload_file(file: UploadFile = File(...), api_key: str = Depends(verify_key)):
    try:
        if not file.content_type.startswith('image/'):
            raise HTTPException(status_code=400, detail="File must be an image")
        
        file_ext = os.path.splitext(file.filename)[1]
        new_filename = f"{uuid.uuid4()}{file_ext}"
        file_path = os.path.join(UPLOAD_DIR, new_filename)
        
        with open(file_path, "wb") as buffer:
            shutil.copyfileobj(file.file, buffer)
            
        return {
            "url": f"http://localhost:8000/uploads/{new_filename}",
            "filename": file.filename
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

# --- Config Operations ---
@app.get("/config", response_model=AppConfig)
def get_config(session: Session = Depends(get_session)):
    config = session.exec(select(AppConfig)).first()
    if not config:
        # Create default config if not exists
        config = AppConfig()
        session.add(config)
        session.commit()
        session.refresh(config)
    return config

@app.post("/config", response_model=AppConfig)
def update_config(new_config: AppConfig, session: Session = Depends(get_session), api_key: str = Depends(verify_key)):
    config = session.exec(select(AppConfig)).first()
    if not config:
        config = AppConfig()
    
    config.appTitle = new_config.appTitle
    config.kicker = new_config.kicker
    config.mainTitle = new_config.mainTitle
    config.subTitle = new_config.subTitle
    config.timelineTitle = new_config.timelineTitle
    config.emptyText = new_config.emptyText
    config.defaultItemTitle = new_config.defaultItemTitle
    config.unknownDateText = new_config.unknownDateText
    
    session.add(config)
    session.commit()
    session.refresh(config)
    return config

@app.post("/items/", response_model=TimelineItem)
def create_item(item: TimelineItem, session: Session = Depends(get_session), api_key: str = Depends(verify_key)):
    # Manual conversion because SQLModel/Pydantic might pass string to SQLite dialect
    if isinstance(item.date, str):
        try:
            if item.date.endswith('Z'):
                item.date = datetime.fromisoformat(item.date[:-1])
            else:
                item.date = datetime.fromisoformat(item.date)
        except Exception:
            pass # Let it fail in session.add if still invalid

    session.add(item)
    session.commit()
    session.refresh(item)
    return item

@app.get("/items/", response_model=List[TimelineItem])
def read_items(session: Session = Depends(get_session)):
    items = session.exec(select(TimelineItem).order_by(TimelineItem.date)).all()
    return items

@app.delete("/items/{item_id}")
def delete_item(item_id: int, session: Session = Depends(get_session), api_key: str = Depends(verify_key)):
    item = session.get(TimelineItem, item_id)
    if not item:
        raise HTTPException(status_code=404, detail="Item not found")
    session.delete(item)
    session.commit()
    return {"ok": True}

if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8000)
