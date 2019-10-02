# Initialize NEON Package
library(neonUtilities)
# Download all phenology files
zipsByProduct(dpID = "DP1.10055.001", site ="all", package = "basic", check.size = F, savepath = paste0(getwd(), "/data-files/") )
#Stack all phenology files
stackByTable(
  filepath = paste0(getwd(), "/data-files/filesToStack10055"), 
  folder = T, 
  saveUnzippedFiles = F, 
  savepath = paste0(getwd(), "/stacked-files") 
)

